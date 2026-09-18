<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure below binds Feature tests to a base test case class. Unit
| tests do not extend this by default, which is deliberate: the proration
| calculator suite in tests/Unit has no database and must stay that way.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
