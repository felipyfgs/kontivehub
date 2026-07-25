<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extensão CT-e completa: qualidade/assinatura em aquisições, metadados de projeção,
 * índices multi-papel e canal CTE_AUTXML_DISTDFE no cursor do escritório.
 *
 * Não recria office_* / document_acquisitions / document_interests — só estende.
 *
 * @see openspec/changes/complete-cte-capture-with-distdfe-autxml-and-import
 */
return new class extends Migration
{
    public function up(): void
    {
        // Qualidade e assinatura na aquisição (dfe_documents permanece imutável)
        if (Schema::hasTable('document_acquisitions')) {
            Schema::table('document_acquisitions', function (Blueprint $table) {
                if (! Schema::hasColumn('document_acquisitions', 'artifact_quality')) {
                    $table->string('artifact_quality', 40)->nullable()->after('sha256');
                }
                if (! Schema::hasColumn('document_acquisitions', 'signature_result')) {
                    $table->string('signature_result', 40)->nullable()->after('artifact_quality');
                }
            });

            if (! $this->indexExists('document_acquisitions', 'document_acquisitions_office_quality')) {
                Schema::table('document_acquisitions', function (Blueprint $table) {
                    $table->index(['office_id', 'artifact_quality'], 'document_acquisitions_office_quality');
                });
            }
            if (! $this->indexExists('document_acquisitions', 'document_acquisitions_office_source_channel')) {
                Schema::table('document_acquisitions', function (Blueprint $table) {
                    $table->index(['office_id', 'source', 'channel'], 'document_acquisitions_office_source_channel');
                });
            }
        }

        // Interesses: múltiplos papéis por mesmo NSU (drop unique antigo estab+env+channel+nsu)
        if (Schema::hasTable('document_interests')) {
            if ($this->indexExists('document_interests', 'document_interests_estab_env_channel_nsu_unique')) {
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->dropUnique('document_interests_estab_env_channel_nsu_unique');
                });
            }

            if (! $this->indexExists('document_interests', 'document_interests_estab_env_ch_nsu_role_unique')) {
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->unique(
                        ['establishment_id', 'environment', 'channel', 'nsu', 'fiscal_role'],
                        'document_interests_estab_env_ch_nsu_role_unique'
                    );
                });
            }

            if (! $this->indexExists('document_interests', 'document_interests_office_role')) {
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->index(['office_id', 'fiscal_role'], 'document_interests_office_role');
                });
            }
            if (! $this->indexExists('document_interests', 'document_interests_estab_channel_nsu')) {
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->index(
                        ['establishment_id', 'channel', 'nsu'],
                        'document_interests_estab_channel_nsu'
                    );
                });
            }
        }

        // Projeção CT-e: expedidor, recebedor, tomador efetivo, versão de schema
        if (Schema::hasTable('cte_documents')) {
            Schema::table('cte_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('cte_documents', 'expeditor_cnpj')) {
                    $table->string('expeditor_cnpj', 14)->nullable()->after('recipient_cnpj');
                }
                if (! Schema::hasColumn('cte_documents', 'expeditor_name')) {
                    $table->string('expeditor_name')->nullable()->after('expeditor_cnpj');
                }
                if (! Schema::hasColumn('cte_documents', 'receiver_cnpj')) {
                    $table->string('receiver_cnpj', 14)->nullable()->after('expeditor_name');
                }
                if (! Schema::hasColumn('cte_documents', 'receiver_name')) {
                    $table->string('receiver_name')->nullable()->after('receiver_cnpj');
                }
                if (! Schema::hasColumn('cte_documents', 'effective_taker_cnpj')) {
                    $table->string('effective_taker_cnpj', 14)->nullable()->after('taker_name');
                }
                if (! Schema::hasColumn('cte_documents', 'schema_version')) {
                    $table->string('schema_version', 20)->nullable()->after('schema_hint');
                }
                if (! Schema::hasColumn('cte_documents', 'protocol_number')) {
                    $table->string('protocol_number', 30)->nullable()->after('official_status_code');
                }
                if (! Schema::hasColumn('cte_documents', 'coverage_status')) {
                    $table->string('coverage_status', 40)->nullable()->after('status');
                }
            });

            if (! $this->indexExists('cte_documents', 'cte_documents_office_expeditor')) {
                Schema::table('cte_documents', function (Blueprint $table) {
                    $table->index(['office_id', 'expeditor_cnpj'], 'cte_documents_office_expeditor');
                    $table->index(['office_id', 'receiver_cnpj'], 'cte_documents_office_receiver');
                    $table->index(['office_id', 'coverage_status'], 'cte_documents_office_coverage');
                });
            }
        }

        // Cobertura agregada por cliente/período (projeção honesta)
        if (! Schema::hasTable('cte_coverage_snapshots')) {
            Schema::create('cte_coverage_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->string('period', 7); // YYYY-MM
                $table->string('status', 40); // CteCoverageStatus
                $table->unsignedInteger('documents_count')->default(0);
                $table->unsignedInteger('original_count')->default(0);
                $table->unsignedInteger('autxml_redacted_count')->default(0);
                $table->unsignedInteger('pending_import_count')->default(0);
                $table->json('metadata')->nullable(); // sem XML / segredos
                $table->timestampTz('computed_at')->nullable();
                $table->timestamps();

                $table->unique(['office_id', 'client_id', 'period'], 'cte_coverage_office_client_period');
                $table->index(['office_id', 'status']);
            });
        }

        // Cursor central CT-e: canal CTE_AUTXML_DISTDFE já cabe em office_distribution_cursors
        // (unique office_id + interested_root_cnpj + environment + channel). Nada a recriar.
        // Índice auxiliar para listar cursores CT-e do office.
        if (Schema::hasTable('office_distribution_cursors')
            && ! $this->indexExists('office_distribution_cursors', 'office_distribution_cursors_office_channel_status')) {
            Schema::table('office_distribution_cursors', function (Blueprint $table) {
                $table->index(
                    ['office_id', 'channel', 'status'],
                    'office_distribution_cursors_office_channel_status'
                );
            });
        }

        // Eventos CT-e imutáveis (projeção)
        if (! Schema::hasTable('cte_events')) {
            Schema::create('cte_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('dfe_document_id')->constrained('dfe_documents')->cascadeOnDelete();
                $table->foreignId('cte_document_id')->nullable()->constrained('cte_documents')->nullOnDelete();
                $table->string('access_key', 50);
                $table->string('event_type', 20)->nullable();
                $table->unsignedSmallInteger('sequence')->nullable();
                $table->string('protocol_number', 30)->nullable();
                $table->string('cstat', 10)->nullable();
                $table->timestampTz('event_at')->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamps();

                $table->unique(
                    ['office_id', 'access_key', 'event_type', 'sequence'],
                    'cte_events_office_key_type_seq'
                );
                $table->index(['office_id', 'access_key']);
                $table->index(['cte_document_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cte_events');
        Schema::dropIfExists('cte_coverage_snapshots');

        if (Schema::hasTable('office_distribution_cursors')
            && $this->indexExists('office_distribution_cursors', 'office_distribution_cursors_office_channel_status')) {
            Schema::table('office_distribution_cursors', function (Blueprint $table) {
                $table->dropIndex('office_distribution_cursors_office_channel_status');
            });
        }

        if (Schema::hasTable('cte_documents')) {
            Schema::table('cte_documents', function (Blueprint $table) {
                if ($this->indexExists('cte_documents', 'cte_documents_office_expeditor')) {
                    $table->dropIndex('cte_documents_office_expeditor');
                    $table->dropIndex('cte_documents_office_receiver');
                    $table->dropIndex('cte_documents_office_coverage');
                }
                $cols = [
                    'expeditor_cnpj', 'expeditor_name', 'receiver_cnpj', 'receiver_name',
                    'effective_taker_cnpj', 'schema_version', 'protocol_number', 'coverage_status',
                ];
                $drop = array_values(array_filter($cols, fn (string $c) => Schema::hasColumn('cte_documents', $c)));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('document_interests')) {
            Schema::table('document_interests', function (Blueprint $table) {
                if ($this->indexExists('document_interests', 'document_interests_office_role')) {
                    $table->dropIndex('document_interests_office_role');
                }
                if ($this->indexExists('document_interests', 'document_interests_estab_channel_nsu')) {
                    $table->dropIndex('document_interests_estab_channel_nsu');
                }
                if ($this->indexExists('document_interests', 'document_interests_estab_env_ch_nsu_role_unique')) {
                    $table->dropUnique('document_interests_estab_env_ch_nsu_role_unique');
                }
            });
            // Restaura unique legado por NSU (sem papel) se possível
            if (! $this->indexExists('document_interests', 'document_interests_estab_env_channel_nsu_unique')) {
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->unique(
                        ['establishment_id', 'environment', 'channel', 'nsu'],
                        'document_interests_estab_env_channel_nsu_unique'
                    );
                });
            }
        }

        if (Schema::hasTable('document_acquisitions')) {
            Schema::table('document_acquisitions', function (Blueprint $table) {
                if ($this->indexExists('document_acquisitions', 'document_acquisitions_office_quality')) {
                    $table->dropIndex('document_acquisitions_office_quality');
                }
                if ($this->indexExists('document_acquisitions', 'document_acquisitions_office_source_channel')) {
                    $table->dropIndex('document_acquisitions_office_source_channel');
                }
                $cols = array_values(array_filter(
                    ['artifact_quality', 'signature_result'],
                    fn (string $c) => Schema::hasColumn('document_acquisitions', $c)
                ));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        // Fallback: assume absent (MySQL etc.)
        return false;
    }
};
