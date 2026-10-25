-- Migration: Connect payment to enrollment and document requests
ALTER TABLE payment
  ADD COLUMN enrollment_id INT NULL DEFAULT NULL AFTER student_id,
  ADD COLUMN request_id INT NULL DEFAULT NULL AFTER enrollment_id,
  ADD KEY idx_payment_enrollment (enrollment_id),
  ADD KEY idx_payment_request (request_id),
  ADD CONSTRAINT fk_payment_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollment (enrollment_id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_payment_doc_request FOREIGN KEY (request_id) REFERENCES document_request (request_id) ON DELETE SET NULL;
