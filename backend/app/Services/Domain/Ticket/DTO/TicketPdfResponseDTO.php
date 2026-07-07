<?php

namespace HiEvents\Services\Domain\Ticket\DTO;

class TicketPdfResponseDTO
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename,
    )
    {
    }
}
