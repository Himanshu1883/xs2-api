<?php

namespace Tests\Unit;

use App\Support\EnglishDisplayText;
use PHPUnit\Framework\TestCase;

class EnglishDisplayTextTest extends TestCase
{
    public function test_it_rejects_arabic_script_labels(): void
    {
        $this->assertNull(EnglishDisplayText::preferEnglish('أرسنال'));
        $this->assertSame('Arsenal vs Burnley', EnglishDisplayText::preferEnglish('Arsenal vs Burnley'));
    }

    public function test_it_resolves_first_latin_candidate(): void
    {
        $this->assertSame(
            'Alpha vs Beta',
            EnglishDisplayText::resolve('أرسنال', 'Alpha vs Beta'),
        );
    }
}
