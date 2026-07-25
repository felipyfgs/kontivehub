<?php

namespace App\Enums;

enum PgdasdDocumentKind: string
{
    case Recibo = 'RECIBO';
    case Declaracao = 'DECLARACAO';
    case Extrato = 'EXTRATO';
    case NotificacaoMaed = 'NOTIFICACAO_MAED';
    case DarfMaed = 'DARF_MAED';
    case GuiaDasPreexistente = 'GUIA_DAS_PREEXISTENTE';
    case Other = 'OTHER';
}
