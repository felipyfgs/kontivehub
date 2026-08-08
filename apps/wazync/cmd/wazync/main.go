package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/broker"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/command"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/config"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/dispatcher"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/httpapi"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/media"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/protocol"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/security"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/session"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/spool"
	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/store"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		slog.Error("invalid Wazync configuration", "error", err.Error())
		os.Exit(1)
	}

	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()

	persistence, dataBox, err := openStore(ctx, cfg)
	if err != nil {
		slog.Error("Wazync store initialization failed", "error", err.Error())
		os.Exit(1)
	}
	defer persistence.Close()
	var deviceResolver *protocol.DeviceResolver
	var mediaSpool *spool.Store
	var messageBroker *broker.JetStream
	clientSettings := protocol.ClientSettings{
		ConnectTimeout:           cfg.WhatsAppConnectTimeout,
		ReadyTimeout:             cfg.WhatsAppReadyTimeout,
		HTTPTimeout:              cfg.WhatsAppHTTPTimeout,
		ProxyAddress:             cfg.WhatsAppProxyURL,
		MaxParallelRetryHandlers: cfg.WhatsAppRetryHandlers,
	}
	if cfg.Enabled {
		mediaSpool, err = spool.Open(cfg.SpoolDirectory, dataBox)
		if err != nil {
			slog.Error("Wazync spool initialization failed")
			os.Exit(1)
		}
		deviceResolver, err = protocol.OpenDeviceResolver(ctx, cfg.DatabaseURL, dataBox, clientSettings)
		if err != nil {
			slog.Error("WhatsMeow device store initialization failed")
			os.Exit(1)
		}
		defer deviceResolver.Close()
		if cfg.NATSURL != "" {
			messageBroker, err = broker.Open(broker.Config{
				URL: cfg.NATSURL, User: cfg.NATSUser, Password: cfg.NATSPassword,
				Stream: cfg.NATSStream, EventSubject: cfg.NATSEventSubject,
				CommandSubject: cfg.NATSCommandSubject, CommandConsumer: cfg.NATSCommandConsumer,
				MaxBodyBytes: cfg.MaxBodyBytes,
			}, persistence)
			if err != nil {
				slog.Error("Wazync JetStream initialization failed", "error", err.Error())
				os.Exit(1)
			}
			defer messageBroker.Close()
		}
	}

	keys := map[string]string{}
	if cfg.CurrentKeyID != "" && cfg.CurrentSecret != "" {
		keys[cfg.CurrentKeyID] = cfg.CurrentSecret
	}
	if cfg.PreviousKeyID != "" && cfg.PreviousSecret != "" {
		keys[cfg.PreviousKeyID] = cfg.PreviousSecret
	}
	verifier := security.NewVerifier(keys, cfg.HMACWindow, cfg.NonceTTL, persistence)
	api := httpapi.New(cfg.Enabled, cfg.MaxBodyBytes, persistence, verifier)
	if messageBroker != nil {
		api.WithBrokerMetrics(messageBroker)
	}
	if cfg.Enabled {
		api.WithSpoolStore(mediaSpool)
		eventBridge := protocol.NewEventBridge(persistence, mediaSpool, cfg.MaxMediaBytes)
		api.WithRecipientScopeMetrics(eventBridge)
		eventBridge.SetDeviceRecorder(deviceResolver)
		deviceResolver.SetEventSink(eventBridge.HandleWithSuccess)
		adapter := protocol.NewWhatsMeowAdapter(deviceResolver, clientSettings).
			WithRecoveryStore(persistence).
			WithStickerMaterialization(persistence, mediaSpool, cfg.MaxMediaBytes)
		api.WithQueryExecutor(adapter).WithSessionInspector(adapter)
		sessionManager := session.NewManager(
			persistence, adapter, cfg.ReplicaID, cfg.SessionCapacity, cfg.LeaseTTL, cfg.HeartbeatEvery,
		)
		eventBridge.SetLifecycleObserver(sessionManager.NotifyLifecycle)
		pairing := session.NewPairingCoordinator(persistence, adapter, deviceResolver)
		mediaSource := media.NewFetcher(
			cfg.LaravelMediaSourceURL, cfg.CurrentKeyID, cfg.CurrentSecret, cfg.MaxMediaBytes, nil,
		)
		worker := command.New(persistence, sessionManager, pairing, adapter, cfg.ReplicaID).
			WithMediaSource(mediaSource)
		eventDispatcher := dispatcher.New(
			persistence, cfg.LaravelEventIngestURL, cfg.CurrentKeyID, cfg.CurrentSecret, nil,
		).WithSpool(mediaSpool)
		if messageBroker != nil {
			eventDispatcher.WithPublisher(messageBroker)
			go func() {
				if err := messageBroker.RunCommandConsumer(ctx); err != nil && !errors.Is(err, context.Canceled) {
					slog.Error("Wazync JetStream command consumer stopped", "error", err.Error())
					cancel()
				}
			}()
		}
		go sessionManager.Run(ctx)
		go worker.Run(ctx, 250*time.Millisecond)
		go eventDispatcher.Run(ctx, time.Second)
	}
	server := newHTTPServer(cfg.HTTPAddress, api.Handler())

	go func() {
		slog.Info("Wazync listening", "enabled", cfg.Enabled)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			slog.Error("Wazync HTTP server stopped", "error", err.Error())
			cancel()
		}
	}()

	<-ctx.Done()
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer shutdownCancel()
	if err := server.Shutdown(shutdownCtx); err != nil {
		slog.Error("Wazync HTTP shutdown failed", "error", err.Error())
	}
}

func newHTTPServer(address string, handler http.Handler) *http.Server {
	return &http.Server{
		Addr:              address,
		Handler:           handler,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		// net/http applies WriteTimeout from request handling through response writes.
		// Leave room for the 15s request-read budget, the longest synchronous query
		// (80s), and response serialization. Query-specific contexts still cap ordinary
		// queries at 15s.
		WriteTimeout: 100 * time.Second,
		IdleTimeout:  60 * time.Second,
	}
}

func openStore(ctx context.Context, cfg config.Config) (store.Store, *cryptobox.Box, error) {
	if !cfg.Enabled {
		return store.NewMemory(), nil, nil
	}
	box, err := cryptobox.New(cfg.DataKey)
	if err != nil {
		return nil, nil, err
	}
	persistence, err := store.OpenPostgres(ctx, cfg.DatabaseURL, box)
	return persistence, box, err
}
