<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Support;

use Breakpoint\GooglePlay\GooglePlayManager;
use Breakpoint\GooglePlay\Validator;

class FakeGooglePlayManager extends GooglePlayManager
{
    public function __construct(
        private FakeValidator $validator,
        private string $packageName = 'com.consumedbycode.slopes',
    ) {
        parent::__construct([]);
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    public function validator(): Validator
    {
        return $this->validator;
    }
}
