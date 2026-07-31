<?php

namespace App\DTO\Work;

final readonly class CommentData
{
    public function __construct(public string $body) {}
}
