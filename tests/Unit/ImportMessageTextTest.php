<?php

namespace Tests\Unit;

use App\Services\Import\ImportMessageText;
use PHPUnit\Framework\TestCase;

/**
 * The import preview shows plain-language notes for technical row messages,
 * while unknown messages pass through unchanged (nothing is hidden).
 */
class ImportMessageTextTest extends TestCase
{
    public function test_legacy_value_messages_become_plain_language(): void
    {
        $this->assertSame(
            '"FARMER" isn\'t one of the standard Occupation options. It is kept exactly as written in the file; review it when editing the record.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'field' => 'occupation',
                'text' => 'legacy_value: "FARMER" is not in the standard list (kept as-is; review on edit)',
            ]),
        );

        $this->assertSame(
            '"VOTER\'S CERTIFICATE" isn\'t one of the standard Type of ID options. It is kept exactly as written in the file; review it when editing the record.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'field' => 'id_type',
                'text' => 'legacy_value: "VOTER\'S CERTIFICATE" is not in the standard list (kept as-is; review on edit)',
            ]),
        );
    }

    public function test_birthdate_messages_become_plain_language(): void
    {
        $this->assertSame(
            '"1991/8//24" isn\'t a valid date. The original value is kept as written and this row needs review.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'field' => 'birthdate',
                'text' => 'birthdate_invalid: "1991/8//24" is not a valid date (needs review; raw value retained)',
            ]),
        );

        $this->assertSame(
            'This row has no birthdate. It needs review before the record is used.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'field' => 'birthdate',
                'text' => 'birthdate_missing: birthdate is empty (needs review; raw value retained)',
            ]),
        );
    }

    public function test_duplicate_and_change_messages_become_plain_language(): void
    {
        $this->assertSame(
            'This ID number also appears in Excel row 23 of the same file.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'field' => 'source_id_number',
                'text' => 'in_file_duplicate: ID NUMBER also appears in Excel row 23',
            ]),
        );

        $this->assertSame(
            'A person with the same name and birthdate already exists (DOLE-0001). Review this row before importing.',
            ImportMessageText::friendly([
                'severity' => 'warning',
                'text' => 'duplicate_name_birthdate: matches existing DOLE-0001',
            ]),
        );

        $this->assertSame(
            'The file changes these fields on the existing record: Occupation, Type of ID.',
            ImportMessageText::friendly([
                'severity' => 'info',
                'text' => 'fields_changed: occupation, id_type',
            ]),
        );
    }

    public function test_unknown_messages_pass_through_unchanged(): void
    {
        $raw = 'some_new_check: something the presenter does not know yet';

        $this->assertSame($raw, ImportMessageText::friendly(['text' => $raw]));

        $this->assertSame(
            'First name and/or last name is missing, so this row can\'t be imported.',
            ImportMessageText::friendly(['text' => 'missing_required: first name and/or last name is empty']),
        );
    }
}
