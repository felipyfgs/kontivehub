package store

import (
	"strings"
	"testing"

	"github.com/jackc/pgx/v5/pgxpool"
)

func TestMigrationUsesOnlyWazyncSchema(t *testing.T) {
	t.Parallel()

	if strings.Contains(strings.ToUpper(migrationSQL), "CREATE SCHEMA") {
		t.Fatal("runtime migration must not create schemas")
	}
	if !strings.Contains(migrationSQL, "CREATE TABLE IF NOT EXISTS wazync.commands") {
		t.Fatal("migration does not qualify tables with the Wazync schema")
	}
	removedSchema := "whatsapp_" + "gateway"
	if strings.Contains(migrationSQL, removedSchema) {
		t.Fatalf("migration references removed schema %q", removedSchema)
	}
}

func TestPgxUsesEnvironmentPasswordWhenURIHasNone(t *testing.T) {
	reservedPassword := "ci:@/?#%local-password"
	t.Setenv("PGPASSWORD", reservedPassword)

	config, err := pgxpool.ParseConfig(
		"postgres://wazync@postgres:5432/nfse?search_path=wazync",
	)
	if err != nil {
		t.Fatalf("parse PostgreSQL config: %v", err)
	}
	if config.ConnConfig.Password != reservedPassword {
		t.Fatal("pgx did not source the password from PGPASSWORD")
	}
}
