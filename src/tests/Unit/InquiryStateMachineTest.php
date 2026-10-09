<?php

namespace Tests\Unit;

use App\Enums\InquiryStatus;
use PHPUnit\Framework\TestCase;

class InquiryStateMachineTest extends TestCase
{
    public function test_allowed_transitions(): void
    {
        $this->assertTrue(InquiryStatus::New->canTransitionTo(InquiryStatus::Qualified));
        $this->assertTrue(InquiryStatus::New->canTransitionTo(InquiryStatus::Rejected));
        $this->assertTrue(InquiryStatus::New->canTransitionTo(InquiryStatus::Archived));
        $this->assertTrue(InquiryStatus::Qualified->canTransitionTo(InquiryStatus::Converted));
    }

    public function test_archived_is_terminal(): void
    {
        $this->assertSame([], InquiryStatus::Archived->transitions());
    }

    public function test_forbidden_transitions(): void
    {
        $this->assertFalse(InquiryStatus::Archived->canTransitionTo(InquiryStatus::New));
        $this->assertFalse(InquiryStatus::Converted->canTransitionTo(InquiryStatus::Rejected));
        $this->assertFalse(InquiryStatus::Rejected->canTransitionTo(InquiryStatus::Qualified));
        $this->assertFalse(InquiryStatus::New->canTransitionTo(InquiryStatus::Archived) === false);
    }

    public function test_labels(): void
    {
        $this->assertSame('Новый', InquiryStatus::New->label());
        $this->assertSame('Архив', InquiryStatus::Archived->label());
    }
}
