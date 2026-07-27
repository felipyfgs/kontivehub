<?php

/**
 * Matriz versionada das rotas Work: leitura vs mutação/exportação.
 *
 * Mutações e exportações: TenantMembership real no Tenant corrente, ou
 * PLATFORM_ADMIN em contexto privilegiado (EnsureWorkRealMembership + policies).
 * Leitura também usa contexto privilegiado PLATFORM_ADMIN.
 *
 * Versão: 1 — individualizar-perfis-plataforma-escritorio
 */
return [
    'version' => 1,
    'updated_at' => '2026-07-23',

    'read' => [
        'GET /api/v1/work/departments',
        'GET /api/v1/work/templates',
        'GET /api/v1/work/templates/{template}',
        'GET /api/v1/work/templates/{template}/recurrence',
        'GET /api/v1/work/templates/{template}/generation-batches',
        'GET /api/v1/work/generation-batches/{batch}',
        'GET /api/v1/work/queue',
        'GET /api/v1/work/process-groups',
        'GET /api/v1/work/processes',
        'GET /api/v1/work/processes/{process}',
        'GET /api/v1/work/processes/{process}/timeline',
        'GET /api/v1/work/tasks/{task}',
        'GET /api/v1/work/tasks/{task}/evidences/{evidence}/download',
        'GET /api/v1/work/kpis',
        'GET /api/v1/work/calendar',
        'GET /api/v1/work/calendar/day',
        'GET /api/v1/work/exports/{export}',
        'GET /api/v1/work/exports/{export}/download',
    ],

    'mutate' => [
        'POST /api/v1/work/departments',
        'PATCH /api/v1/work/departments/{department}',
        'POST /api/v1/work/departments/{department}/assign-membership',
        'POST /api/v1/work/templates',
        'PATCH /api/v1/work/templates/{template}',
        'PATCH /api/v1/work/templates/{template}/recurrence',
        'POST /api/v1/work/templates/{template}/preview',
        'POST /api/v1/work/generation-batches/{batch}/confirm',
        'POST /api/v1/work/generation-batches/{batch}/retry',
        'POST /api/v1/work/processes',
        'PATCH /api/v1/work/processes/{process}',
        'POST /api/v1/work/processes/{process}/archive',
        'POST /api/v1/work/processes/{process}/comments',
        'POST /api/v1/work/processes/{process}/tasks',
        'POST /api/v1/work/processes/{process}/tasks/reorder',
        'PATCH /api/v1/work/tasks/{task}/structure',
        'POST /api/v1/work/tasks/{task}/start',
        'POST /api/v1/work/tasks/{task}/block',
        'POST /api/v1/work/tasks/{task}/resume',
        'POST /api/v1/work/tasks/{task}/complete',
        'POST /api/v1/work/tasks/{task}/dispense',
        'POST /api/v1/work/tasks/{task}/reopen',
        'POST /api/v1/work/tasks/{task}/claim',
        'POST /api/v1/work/tasks/{task}/assign',
        'POST /api/v1/work/tasks/{task}/comments',
        'POST /api/v1/work/tasks/{task}/evidences',
        'DELETE /api/v1/work/tasks/{task}/evidences/{evidence}',
        'POST /api/v1/work/tasks/bulk',
        'POST /api/v1/work/exports',
    ],
];
