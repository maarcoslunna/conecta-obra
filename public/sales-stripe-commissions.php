<?php
declare(strict_types=1);

/*
 * ConectaObra: 30 % por cada factura de suscripción pagada.
 * Incluye contratación inicial y renovaciones.
 * No realiza transferencias ni cobros.
 */
function coSalesCommissionCents(array $invoice): int
{
    $paid = $invoice['amount_paid'] ?? null;
    $total = $invoice['total'] ?? null;
    $due = $invoice['amount_due'] ?? null;
    if (!is_int($paid) || $paid <= 0 || $paid !== $total || $paid !== $due) {
        throw new UnexpectedValueException('Importes de factura Stripe sin conciliar.');
    }

    foreach (['pre_payment_credit_notes_amount', 'post_payment_credit_notes_amount', 'starting_balance'] as $field) {
        if (array_key_exists($field, $invoice) && $invoice[$field] !== 0) {
            throw new UnexpectedValueException('Factura Stripe con crédito o saldo por conciliar.');
        }
    }

    if (array_key_exists('credit_notes', $invoice)) {
        $notes = $invoice['credit_notes'];
        if (!is_array($notes) || ($notes['data'] ?? null) !== [] || !empty($notes['has_more'])) {
            throw new UnexpectedValueException('Factura Stripe con abonos por conciliar.');
        }
    }

    $net = $invoice['total_excluding_tax'] ?? null;
    if ($net !== null && !is_int($net)) {
        throw new UnexpectedValueException('Base sin IVA de factura Stripe inválida.');
    }

    $tax = $invoice['tax'] ?? null;
    if ($tax !== null) {
        if (!is_int($tax) || $tax < 0 || $tax > $total ||
            ($net !== null && $net !== $total - $tax)) {
            throw new UnexpectedValueException('Datos fiscales de factura Stripe inconsistentes.');
        }
        $net = $total - $tax;
    }

    if ($net === null || $net < 0 || $net > $total) {
        throw new UnexpectedValueException('Base sin IVA de factura Stripe no verificable.');
    }

    return intdiv($net * 30 + 50, 100);
}

function coRegisterSalesCommission(PDO $db, array $invoice): void
{
    if (($invoice['status'] ?? '') !== 'paid') return;

    $invoiceId = (string)($invoice['id'] ?? '');
    if ($invoiceId === '') return;

    $reason = (string)($invoice['billing_reason'] ?? '');
    if (!in_array($reason, ['subscription_create', 'subscription_cycle', 'subscription_update'], true)) return;

    $subscriptionId = (string)($invoice['subscription'] ?? '');
    if ($subscriptionId === '') return;

    $amount = (int)($invoice['amount_paid'] ?? 0);
    if ($amount <= 0) return;

    if (strtolower((string)($invoice['currency'] ?? '')) !== 'eur') return;

    $db->beginTransaction();

    try {
        $s = $db->prepare(
            "SELECT l.id AS lead_id, l.agent_id
             FROM subscriptions s
             LEFT JOIN sales_leads l ON l.company_id = s.user_id
             WHERE s.stripe_subscription_id = ?
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $s->execute([$subscriptionId]);
        $lead = $s->fetch(PDO::FETCH_ASSOC);

        if ($lead === false) {
            throw new RuntimeException('Suscripción de factura Stripe pendiente de vincular.');
        }

        if ($lead['lead_id'] === null) {
            $db->commit();
            return;
        }

        $commission = coSalesCommissionCents($invoice);
        if ($commission === 0) {
            $db->commit();
            return;
        }

        $s = $db->prepare(
            "INSERT INTO sales_commissions
             (agent_id, lead_id, stripe_payment_id,
              amount_cents, status, paid_at_stripe, payment_period)
             VALUES (?, ?, ?, ?, 'pending', NOW(), ?)
             ON DUPLICATE KEY UPDATE id = id"
        );
        $s->execute([
            (int)$lead['agent_id'],
            (int)$lead['lead_id'],
            $invoiceId,
            $commission,
            date('Y-m')
        ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
