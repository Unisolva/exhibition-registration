<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Models\FieldDefinition;
use App\Registration\Normalizer;
use App\Registration\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work order §5 and §10.2.
 */
class NormalizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $input
     */
    private function normalize(array $input, string $locale = 'en'): Submission
    {
        return app(Normalizer::class)->normalize($input, FieldDefinition::activeInOrder(), 'EG', $locale);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function egyptianMobileForms(): array
    {
        return [
            'national' => ['01001234567'],
            'international' => ['+201001234567'],
            'double zero' => ['00201001234567'],
            'eastern arabic digits' => ['٠١٠٠١٢٣٤٥٦٧'],
            'extended arabic-indic digits' => ['۰۱۰۰۱۲۳۴۵۶۷'],
            'arabic digits with +' => ['+٢٠١٠٠١٢٣٤٥٦٧'],
            'dashes' => ['010-0123-4567'],
            'dots' => ['010.0123.4567'],
            'brackets and spaces' => ['(010) 0123 4567'],
            'surrounding space' => ['  01001234567  '],
        ];
    }

    #[DataProvider('egyptianMobileForms')]
    public function test_every_way_of_writing_one_number_gives_the_same_key(string $phone): void
    {
        $s = $this->normalize(['name' => 'A', 'phone' => $phone]);

        $this->assertSame([], $s->errors);
        $this->assertSame('+201001234567', $s->phoneKey);
        $this->assertSame('+201001234567', $s->phoneE164);
        $this->assertTrue($s->phoneValid);
    }

    public function test_the_raw_phone_is_kept_as_typed(): void
    {
        $this->assertSame('٠١٠٠ ١٢٣ ٤٥٦٧', $this->normalize(['name' => 'A', 'phone' => ' ٠١٠٠ ١٢٣ ٤٥٦٧ '])->phoneRaw);
    }

    public function test_an_unparseable_number_is_refused(): void
    {
        // M10 CO-6: before 1.3.0 it was kept and flagged.
        $s = $this->normalize(['name' => 'A', 'phone' => '+999 123 4567']);

        $this->assertArrayHasKey('phone', $s->errors);
        $this->assertFalse($s->phoneValid);
        $this->assertNull($s->phoneE164);
    }

    public function test_a_parseable_but_invalid_number_is_refused_with_the_right_wording(): void
    {
        // M10 CO-6: the public wording in the visitor's language, the staff wording on staff screens.
        $s = $this->normalize(['name' => 'A', 'phone' => '0100123']);

        $this->assertSame(['phone' => "This phone number doesn't look right. Please check it."], $s->errors);
        $this->assertFalse($s->phoneValid);
        $this->assertNull($s->phoneE164);
        $this->assertStringStartsWith('+20', $s->phoneKey);

        $staff = app(Normalizer::class)->normalize(['name' => 'A', 'phone' => '0100123'],
            FieldDefinition::activeInOrder(), 'EG', 'en', staff: true);
        $this->assertSame(['phone' => Normalizer::STAFF_PHONE_INVALID], $staff->errors);
    }

    public function test_fewer_than_seven_digits_is_rejected(): void
    {
        $this->assertArrayHasKey('phone', $this->normalize(['name' => 'A', 'phone' => '12-34-56'])->errors);
        $this->assertArrayHasKey('phone', $this->normalize(['name' => 'A', 'phone' => '٠١٢٣٤٥'])->errors);
    }

    public function test_name_is_trimmed_and_inner_whitespace_collapsed_unicode_kept(): void
    {
        $this->assertSame('محمد أحمد', $this->normalize(['name' => "  محمد \t  أحمد  ", 'phone' => '01001234567'])->name);
    }

    public function test_blank_optional_values_become_null_and_required_ones_fail(): void
    {
        $s = $this->normalize(['name' => '   ', 'phone' => '', 'email' => '  ', 'occupation' => '', 'governorate' => '']);

        $this->assertNull($s->email);
        $this->assertNull($s->occupation);
        $this->assertNull($s->regionCode);
        $this->assertArrayHasKey('name', $s->errors);
        $this->assertArrayHasKey('phone', $s->errors);
        $this->assertArrayNotHasKey('email', $s->errors);
    }

    public function test_email_domain_is_lowercased_and_syntax_checked(): void
    {
        $this->assertSame('Visitor.Name@example.test', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'email' => 'Visitor.Name@EXAMPLE.Test'])->email);
        $this->assertArrayHasKey('email', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'email' => 'not an email'])->errors);
        $this->assertArrayHasKey('email', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'email' => 'a@@example.test'])->errors);
    }

    public function test_region_must_belong_to_the_default_country_and_is_snapshotted(): void
    {
        $ok = $this->normalize(['name' => 'A', 'phone' => '01001234567', 'governorate' => 'ALX']);
        $this->assertSame('ALX', $ok->regionCode);
        $this->assertSame('Alexandria', $ok->regionLabel);

        $this->assertArrayHasKey('governorate', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'governorate' => 'XX'])->errors);
    }

    public function test_selects_accept_only_active_options_and_empty_options_mean_free_text(): void
    {
        $this->assertSame('Seed trader', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'occupation' => 'Seed trader'])->occupation);

        FieldDefinition::query()->where('key', 'occupation')->update(['options' => json_encode([
            ['value' => 'farmer', 'label_en' => 'Farmer', 'label_ar' => 'مزارع', 'is_active' => true],
            ['value' => 'student', 'label_en' => 'Student', 'label_ar' => 'طالب', 'is_active' => false],
        ])]);

        $this->assertSame('farmer', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'occupation' => 'farmer'])->occupation);
        $this->assertArrayHasKey('occupation', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'occupation' => 'student'])->errors);
        $this->assertArrayHasKey('occupation', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'occupation' => 'Seed trader'])->errors);
    }

    public function test_length_limits_apply(): void
    {
        $this->assertArrayHasKey('name', $this->normalize(['name' => str_repeat('a', 151), 'phone' => '01001234567'])->errors);
        $this->assertArrayHasKey('phone', $this->normalize(['name' => 'A', 'phone' => str_repeat('1', 41)])->errors);
        $this->assertArrayHasKey('occupation', $this->normalize(['name' => 'A', 'phone' => '01001234567', 'occupation' => str_repeat('a', 101)])->errors);
    }

    public function test_custom_fields_go_to_extra_and_digit_only_values_become_ascii(): void
    {
        FieldDefinition::query()->create([
            'key' => 'farm_size', 'is_system' => false, 'type' => 'text', 'label_en' => 'Farm size', 'label_ar' => 'مساحة المزرعة',
            'is_required' => false, 'is_active' => true, 'sort_order' => 100, 'send_to_erp' => true,
        ]);

        $s = $this->normalize(['name' => 'A', 'phone' => '01001234567', 'farm_size' => '١٢٠']);
        $this->assertSame(['farm_size' => '120'], $s->extra);

        $s = $this->normalize(['name' => 'A', 'phone' => '01001234567', 'farm_size' => '12 فدان']);
        $this->assertSame(['farm_size' => '12 فدان'], $s->extra);
    }

    public function test_inactive_fields_and_unknown_keys_are_ignored(): void
    {
        FieldDefinition::query()->where('key', 'email')->update(['is_active' => false]);

        $s = $this->normalize(['name' => 'A', 'phone' => '01001234567', 'email' => 'x@example.test', 'is_admin' => '1']);

        $this->assertNull($s->email);
        $this->assertSame([], $s->extra);
    }

    public function test_non_string_input_is_treated_as_empty(): void
    {
        $s = app(Normalizer::class)->normalize(['name' => ['x'], 'phone' => '01001234567'], FieldDefinition::activeInOrder(), 'EG', 'en');

        $this->assertArrayHasKey('name', $s->errors);
    }

    public function test_the_hash_depends_on_normalised_values_and_exhibition_only(): void
    {
        $a = $this->normalize(['name' => 'Invented  Person', 'phone' => '٠١٠٠١٢٣٤٥٦٧', 'email' => 'a@EXAMPLE.test']);
        $b = $this->normalize(['email' => 'a@example.test', 'phone' => '+20 100 123 4567', 'name' => ' Invented Person ']);
        $c = $this->normalize(['name' => 'Invented Person', 'phone' => '01001234568', 'email' => 'a@example.test']);

        $this->assertSame($a->hash(1), $b->hash(1));
        $this->assertNotSame($a->hash(1), $a->hash(2));
        $this->assertNotSame($a->hash(1), $c->hash(1));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->hash(1));
    }
}
