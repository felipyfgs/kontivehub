<?php

namespace App\Enums;

enum AuthorIdentityType: string
{
    case Cpf = 'CPF';
    case Cnpj = 'CNPJ';
}
