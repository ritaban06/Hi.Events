<?php

namespace HiEvents\Services\Domain\Attendee;

use Barryvdh\DomPDF\Facade\Pdf;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use Illuminate\Support\Collection;

class GenerateTicketPdfService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @param Collection<AttendeeDomainObject> $attendees
     * @param EventDomainObject $event
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generate(Collection $attendees, EventDomainObject $event): \Barryvdh\DomPDF\PDF
    {
        // Load products for the attendees if they are not already loaded
        $productIds = $attendees->map(fn($a) => $a->getProductId())->unique()->filter()->toArray();
        $products = collect();

        if (!empty($productIds)) {
            $products = $this->productRepository->findWhereIn('id', $productIds);
        }

        // Map product to attendees
        foreach ($attendees as $attendee) {
            if (!$attendee->getProduct() && $attendee->getProductId()) {
                $product = $products->firstWhere('id', $attendee->getProductId());
                if ($product instanceof ProductDomainObject) {
                    $attendee->setProduct($product);
                }
            }
        }

        // Set paper to A4 or custom size if needed, but DomPDF defaults to A4
        return Pdf::loadView('ticket', [
            'attendees' => $attendees,
            'event' => $event,
            'eventSettings' => $event->getEventSettings(),
        ])->setOption(['isRemoteEnabled' => true]);
    }
}
