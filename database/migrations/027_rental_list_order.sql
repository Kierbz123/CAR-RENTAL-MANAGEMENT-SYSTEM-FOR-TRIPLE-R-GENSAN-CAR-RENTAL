-- The rental list is shown open bookings first (reserved, confirmed, active, returned, then
-- completed, cancelled, no-show), by start date. Sorting on FIELD(status, ...) made MySQL read
-- and sort every agreement on every page view. The order is now a stored column with an index,
-- so a page of 25 is read straight from the index.

ALTER TABLE rental_agreements
    ADD COLUMN status_rank TINYINT UNSIGNED GENERATED ALWAYS AS (FIELD(status, 'reserved', 'confirmed', 'active', 'returned', 'completed', 'cancelled', 'no_show')) STORED,
    ADD KEY idx_rentals_list_order (status_rank, start_date, agreement_id);
