package protocol

import (
	"strings"
	"testing"
)

func TestDirectWhatsMeowQueriesUseWazyncSchema(t *testing.T) {
	t.Parallel()

	for name, query := range map[string]string{
		"lookup": hasWhatsMeowDeviceSQL,
		"delete": deleteWhatsMeowDeviceSQL,
	} {
		if !strings.Contains(query, "wazync.whatsmeow_device") {
			t.Fatalf("%s query does not use the Wazync schema: %q", name, query)
		}
		removedSchema := "whatsapp_" + "gateway"
		if strings.Contains(query, removedSchema) {
			t.Fatalf("%s query references removed schema %q", name, removedSchema)
		}
	}
}

func TestWhatsMeowConnectionsForceWazyncSearchPath(t *testing.T) {
	t.Parallel()

	databaseConfig, err := wazyncDatabaseConfig("postgres://wazync@postgres/nfse?search_path=public")
	if err != nil {
		t.Fatalf("parse Wazync database URL: %v", err)
	}
	if got := databaseConfig.RuntimeParams["search_path"]; got != "wazync" {
		t.Fatalf("unexpected WhatsMeow search_path: %q", got)
	}
}
