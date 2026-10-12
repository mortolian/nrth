<?php

namespace Tests\Unit;

use App\Support\PageSize;
use Illuminate\Http\Request;
use Tests\TestCase;

class PageSizeTest extends TestCase
{
    public function test_accepts_a_known_page_size(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '50']);

        $this->assertSame(50, PageSize::resolve($request, 15));
    }

    public function test_falls_back_when_the_page_size_is_not_allowed(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '999']);

        $this->assertSame(15, PageSize::resolve($request, 15));
        $this->assertSame(25, PageSize::resolve(Request::create('/'), 25));
    }
}
