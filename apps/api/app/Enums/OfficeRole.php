<?php

namespace App\Enums;

enum OfficeRole: string
{
    case Admin = 'ADMIN';
    case Operator = 'OPERATOR';
    case Viewer = 'VIEWER';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function canManageClients(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    public function canManageCredentials(): bool
    {
        return $this === self::Admin;
    }

    public function canTriggerSync(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Manifestação do destinatário / unlock XML (OPERATOR/ADMIN). */
    public function canManifestNfe(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    public function canExport(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Publicar/compartilhar filtros salvos com o Office (ADMIN|OPERATOR; VIEWER só pessoais). */
    public function canShareListFilters(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Importação de XML de saída (OPERATOR/ADMIN). */
    public function canImportDocuments(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Operações fiscais mutantes (emissão/transmissão) — somente ADMIN. */
    public function canMutateFiscal(): bool
    {
        return $this === self::Admin;
    }

    // ── Capacidades do módulo operacional (Work) ──────────────────────────
    // Personas: gestor → ADMIN; executor → OPERATOR; consulta → VIEWER.
    // Não cria papéis novos; não concede acesso a cliente final.

    /** Administrar departamentos e modelos de processo. */
    public function canManageWorkCatalog(): bool
    {
        return $this === self::Admin;
    }

    /** Criar processos manuais e gerar a partir de modelos. */
    public function canCreateWorkProcesses(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Executar tarefas (iniciar, impedir, concluir, comentar, anexar). */
    public function canExecuteWorkTasks(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Reatribuir, alterar prazos, dispensar, reabrir e ações em lote. */
    public function canAdministerWork(): bool
    {
        return $this === self::Admin;
    }

    /** Consultar fila, processos, calendário e KPIs operacionais. */
    public function canViewWork(): bool
    {
        return true;
    }

    /** Download de evidências de tarefa (VIEWER somente leitura sem download). */
    public function canDownloadWorkEvidence(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Export CSV operacional. */
    public function canExportWork(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }
}
