package spool

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/inovaicontabil/fiscal-hub/apps/wazync/internal/cryptobox"
)

const (
	magic     = "WZSP1"
	chunkSize = 64 << 10
	nonceSize = 12
)

var idPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$`)

type Record struct {
	ID        string
	SizeBytes int64
	SHA256    string
}

type Store struct {
	directory string
	box       *cryptobox.Box
}

func Open(directory string, box *cryptobox.Box) (*Store, error) {
	if box == nil {
		return nil, errors.New("spool encryption is required")
	}
	if err := os.MkdirAll(directory, 0o700); err != nil {
		return nil, fmt.Errorf("create spool directory: %w", err)
	}
	if err := os.Chmod(directory, 0o700); err != nil {
		return nil, fmt.Errorf("protect spool directory: %w", err)
	}
	return &Store{directory: directory, box: box}, nil
}

func (s *Store) Put(ctx context.Context, id string, source io.Reader) (record Record, err error) {
	if !idPattern.MatchString(id) {
		return Record{}, errors.New("invalid spool identifier")
	}
	temporary, err := os.CreateTemp(s.directory, ".incoming-*")
	if err != nil {
		return Record{}, fmt.Errorf("create temporary spool: %w", err)
	}
	temporaryName := temporary.Name()
	defer func() {
		_ = temporary.Close()
		if err != nil {
			_ = os.Remove(temporaryName)
		}
	}()
	if err = temporary.Chmod(0o600); err != nil {
		return Record{}, err
	}
	writer := bufio.NewWriter(temporary)
	if _, err = writer.WriteString(magic); err != nil {
		return Record{}, err
	}

	hasher := sha256.New()
	buffer := make([]byte, chunkSize)
	var index uint64
	for {
		if err = ctx.Err(); err != nil {
			return Record{}, err
		}
		read, readErr := source.Read(buffer)
		if read > 0 {
			plain := buffer[:read]
			_, _ = hasher.Write(plain)
			record.SizeBytes += int64(read)
			ciphertext, nonce, sealErr := s.box.Seal(plain, associatedData(id, index))
			if sealErr != nil {
				return Record{}, sealErr
			}
			if len(nonce) != nonceSize {
				return Record{}, errors.New("unexpected spool nonce size")
			}
			if err = binary.Write(writer, binary.BigEndian, uint32(len(ciphertext))); err != nil {
				return Record{}, err
			}
			if _, err = writer.Write(nonce); err != nil {
				return Record{}, err
			}
			if _, err = writer.Write(ciphertext); err != nil {
				return Record{}, err
			}
			index++
		}
		if errors.Is(readErr, io.EOF) {
			break
		}
		if readErr != nil {
			return Record{}, readErr
		}
	}
	if err = binary.Write(writer, binary.BigEndian, uint32(0)); err != nil {
		return Record{}, err
	}
	if err = writer.Flush(); err != nil {
		return Record{}, err
	}
	if err = temporary.Sync(); err != nil {
		return Record{}, err
	}
	if err = temporary.Close(); err != nil {
		return Record{}, err
	}
	if err = os.Rename(temporaryName, s.path(id)); err != nil {
		return Record{}, fmt.Errorf("commit spool file: %w", err)
	}
	record.ID = id
	record.SHA256 = hex.EncodeToString(hasher.Sum(nil))
	return record, nil
}

func (s *Store) Reader(ctx context.Context, id string) (io.ReadCloser, error) {
	if !idPattern.MatchString(id) {
		return nil, errors.New("invalid spool identifier")
	}
	file, err := os.Open(s.path(id))
	if err != nil {
		return nil, err
	}
	reader, writer := io.Pipe()
	go func() {
		defer file.Close()
		buffered := bufio.NewReader(file)
		header := make([]byte, len(magic))
		if _, err := io.ReadFull(buffered, header); err != nil || string(header) != magic {
			_ = writer.CloseWithError(errors.New("invalid spool header"))
			return
		}
		var index uint64
		for {
			if err := ctx.Err(); err != nil {
				_ = writer.CloseWithError(err)
				return
			}
			var length uint32
			if err := binary.Read(buffered, binary.BigEndian, &length); err != nil {
				_ = writer.CloseWithError(err)
				return
			}
			if length == 0 {
				_ = writer.Close()
				return
			}
			nonce := make([]byte, nonceSize)
			ciphertext := make([]byte, length)
			if _, err := io.ReadFull(buffered, nonce); err != nil {
				_ = writer.CloseWithError(err)
				return
			}
			if _, err := io.ReadFull(buffered, ciphertext); err != nil {
				_ = writer.CloseWithError(err)
				return
			}
			plain, err := s.box.Open(ciphertext, nonce, associatedData(id, index))
			if err != nil {
				_ = writer.CloseWithError(errors.New("spool authentication failed"))
				return
			}
			if _, err := writer.Write(plain); err != nil {
				return
			}
			index++
		}
	}()
	return reader, nil
}

func (s *Store) Ack(id string) error {
	if !idPattern.MatchString(id) {
		return errors.New("invalid spool identifier")
	}
	err := os.Remove(s.path(id))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}

func (s *Store) Count() (int64, error) {
	entries, err := os.ReadDir(s.directory)
	if err != nil {
		return 0, err
	}
	var count int64
	for _, entry := range entries {
		if !entry.IsDir() && strings.HasSuffix(entry.Name(), ".spool") {
			count++
		}
	}
	return count, nil
}

func (s *Store) path(id string) string {
	return filepath.Join(s.directory, id+".spool")
}

func associatedData(id string, index uint64) []byte {
	return []byte(fmt.Sprintf("%s:%d", id, index))
}
