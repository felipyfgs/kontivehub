package cryptobox

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"errors"
	"io"
)

type Box struct {
	aead cipher.AEAD
	key  []byte
}

func New(key []byte) (*Box, error) {
	if len(key) != 32 {
		return nil, errors.New("data key must contain 32 bytes")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}
	owned := make([]byte, len(key))
	copy(owned, key)
	return &Box{aead: aead, key: owned}, nil
}

func (b *Box) Seal(plain, associatedData []byte) (ciphertext, nonce []byte, err error) {
	nonce = make([]byte, b.aead.NonceSize())
	if _, err = io.ReadFull(rand.Reader, nonce); err != nil {
		return nil, nil, err
	}
	return b.aead.Seal(nil, nonce, plain, associatedData), nonce, nil
}

func (b *Box) Open(ciphertext, nonce, associatedData []byte) ([]byte, error) {
	return b.aead.Open(nil, nonce, ciphertext, associatedData)
}

// Digest returns a keyed hash suitable for unique indexes without exposing plaintext.
func (b *Box) Digest(plain []byte) []byte {
	mac := hmac.New(sha256.New, b.key)
	_, _ = mac.Write(plain)
	return mac.Sum(nil)
}
