<?php

namespace App\Enums;

/**
 * Estado da consulta de mensagens da Caixa Postal (não confundir com DTE).
 */
enum MailboxMessagesConsultStatus: string
{
    case Unknown = 'UNKNOWN';
    case Consulted = 'CONSULTED';
    case Error = 'ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Mensagens não consultadas',
            self::Consulted => 'Mensagens consultadas',
            self::Error => 'Erro na consulta de mensagens',
        };
    }
}
