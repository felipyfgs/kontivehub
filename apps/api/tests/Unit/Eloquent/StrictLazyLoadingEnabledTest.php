<?php

namespace Tests\Unit\Eloquent;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StrictLazyLoadingEnabledTest extends TestCase
{
    #[Test]
    public function testing_environment_prevents_lazy_loading(): void
    {
        self::assertTrue(
            Model::preventsLazyLoading(),
            'Strict lazy loading deve estar ativo em local/testing.',
        );
    }
}
