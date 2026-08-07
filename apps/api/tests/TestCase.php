<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! config()->has('filesystems.disks.communication_media.root') || str_starts_with((string) config('filesystems.disks.communication_media.root'), '/var/vault')) {
            $root = sys_get_temp_dir().'/kontivehub-tests-'.Str::ulid().'/communication';
            config([
                'filesystems.disks.communication_media.root' => $root,
                'communication.media.disk_root' => $root,
            ]);
        }
    }
}
