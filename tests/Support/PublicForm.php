<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Support;

use App\Models\Exhibition;
use Illuminate\Testing\TestResponse;

/**
 * Opens the public form and submits it the way a browser does, with the
 * hidden fields from the rendered page.
 */
trait PublicForm
{
    /**
     * @return array{_token: string, submission_token: string}
     */
    protected function openPublicForm(Exhibition $exhibition): array
    {
        $html = $this->get('/r/'.$exhibition->public_token)->assertOk()->getContent();

        return [
            '_token' => $this->hiddenValue($html, '_token'),
            'submission_token' => $this->hiddenValue($html, 'submission_token'),
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>|null  $hidden  hidden fields of an earlier render (a replay)
     */
    protected function submitPublicForm(Exhibition $exhibition, array $values, ?array $hidden = null): TestResponse
    {
        $hidden ??= $this->openPublicForm($exhibition);

        return $this->post('/r/'.$exhibition->public_token, [...$hidden, ...$values]);
    }

    protected function hiddenValue(string $html, string $name): string
    {
        preg_match('/name="'.preg_quote($name, '/').'" value="([^"]*)"/', $html, $m);

        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }
}
