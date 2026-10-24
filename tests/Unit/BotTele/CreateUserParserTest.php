<?php

namespace Tests\Unit\BotTele;

use App\Services\BotTele\CreateUserParser;
use PHPUnit\Framework\TestCase;

class CreateUserParserTest extends TestCase
{
    private function parse(string $text): array
    {
        return (new CreateUserParser)->parse($text);
    }

    public function test_single_email_and_role(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com\nMaster");

        $this->assertTrue($result['valid']);
        $this->assertSame(['admin@kingspay.com'], $result['emails']);
        $this->assertSame(['Master'], $result['roles']);
        $this->assertSame("Nama Merchant : Kingspay H+1\nNama Grup Merchant : Kingspay\nUser Email : admin@kingspay.com\nRole : Master", $result['description']);
    }

    public function test_comma_separated_emails_and_roles(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com\nMaster, Finance");

        $this->assertTrue($result['valid']);
        $this->assertSame(['admin@kingspay.com', 'finance@kingspay.com'], $result['emails']);
        $this->assertSame(['Master', 'Finance'], $result['roles']);
        $this->assertSame("Nama Merchant : Kingspay H+1\nNama Grup Merchant : Kingspay\nUser Email : admin@kingspay.com, finance@kingspay.com\nRole : Master, Finance", $result['description']);
    }

    public function test_labelled_format_with_continuation_line_matches_unlabelled_result(): void
    {
        $labelled = $this->parse("Merchant: Kingspay H+1\nMerchant Group: Kingspay\nEmail: admin@kingspay.com\nfinance@kingspay.com\nRole: Master, Finance");
        $plain = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com\nMaster, Finance");

        $this->assertTrue($labelled['valid']);
        $this->assertSame($plain, $labelled);
    }

    public function test_invalid_email_is_reported(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com, bukanemail\nMaster");

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Email tidak valid: bukanemail', $result['missing']);
        $this->assertSame('Email tidak valid: bukanemail', $result['missing']);
    }

    public function test_three_unlabelled_lines_blank_every_field(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com");

        $this->assertFalse($result['valid']);
        $this->assertSame('Nama Merchant; Nama Grup Merchant; User Email; Role; Minimal 4 baris', $result['missing']);
        $this->assertSame('', $result['description']);
    }

    public function test_duplicate_emails_keep_first_position_and_last_casing(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nAdmin@Kingspay.com, admin@kingspay.com\nMaster");

        $this->assertTrue($result['valid']);
        $this->assertSame(['admin@kingspay.com'], $result['emails']);
    }

    public function test_roles_split_on_comma_only_not_whitespace(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com\nMaster Finance");

        $this->assertTrue($result['valid']);
        $this->assertSame(['Master Finance'], $result['roles']);
    }

    public function test_empty_email_label_takes_following_lines_as_emails(): void
    {
        $result = $this->parse("Merchant: Kingspay H+1\nMerchant Group: Kingspay\nEmail:\nadmin@kingspay.com\nfinance@kingspay.com\nRole: Master");

        $this->assertTrue($result['valid']);
        $this->assertSame(['admin@kingspay.com', 'finance@kingspay.com'], $result['emails']);
        $this->assertSame(['Master'], $result['roles']);
    }

    public function test_dedupe_keeps_first_position_when_later_duplicate_changes_casing(): void
    {
        $result = $this->parse("Kingspay H+1\nKingspay\nadmin@kingspay.com, finance@kingspay.com, ADMIN@kingspay.com\nmaster, Finance, Master");

        $this->assertSame(['ADMIN@kingspay.com', 'finance@kingspay.com'], $result['emails']);
        $this->assertSame(['Master', 'Finance'], $result['roles']);
    }
}
