<?php
/**
 * ===================================================================
 * SLA DISCOUNT HELPER FUNCTIONS
 * ===================================================================
 * Purpose: Calculate and manage SLA-based discounts for customers
 * Features:
 * - Query latest SLA data for customer
 * - Calculate discount percentage (100 - SLA%)
 * - Generate invoice breakdown with discount
 * - Render discount display HTML
 * ===================================================================
 */

if (!function_exists('slaDiscountGetConfig')) {
    /**
     * Get SLA discount configuration from JSON file
     */
    function slaDiscountGetConfig(): array
    {
        $configFile = __DIR__ . '/../notifbot/data/sla_discount_config.json';
        $default = [
            'enabled' => true,
            'min_sla_percent' => 0,
            'max_discount_percent' => 100,
            'apply_to_manual_only' => false,
            'show_breakdown' => true
        ];

        if (!file_exists($configFile)) {
            // Create default config if not exists
            @mkdir(dirname($configFile), 0755, true);
            file_put_contents($configFile, json_encode($default, JSON_PRETTY_PRINT));
            return $default;
        }

        $config = json_decode(file_get_contents($configFile), true);
        return is_array($config) ? array_merge($default, $config) : $default;
    }
}

if (!function_exists('slaDiscountIsEnabled')) {
    /**
     * Check if SLA discount feature is enabled
     */
    function slaDiscountIsEnabled(): bool
    {
        $config = slaDiscountGetConfig();
        return $config['enabled'] ?? true;
    }
}

if (!function_exists('slaDiscountGetCustomerSlaData')) {
    /**
     * Get latest SLA data for a customer
     * 
     * @param mysqli $conn Database connection
     * @param string $idpel Customer ID (IDPEL)
     * @param string $pemilik Owner/Server name
     * @return array|null SLA data or null if not found
     */
    function slaDiscountGetCustomerSlaData(mysqli $conn, string $idpel, string $pemilik): ?array
    {
        if (empty($idpel) || empty($pemilik)) {
            return null;
        }

        $idpelEsc = mysqli_real_escape_string($conn, $idpel);
        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

        $sql = "SELECT 
                    id,
                    snapshot_month,
                    snapshot_date,
                    total_sla_percent,
                    total_checks,
                    online_checks,
                    odp,
                    area
                FROM customer_sla_monthly_snapshots
                WHERE idpel = '$idpelEsc' 
                  AND pemilik = '$pemilikEsc'
                ORDER BY snapshot_month DESC, snapshot_date DESC
                LIMIT 1";

        $result = mysqli_query($conn, $sql);
        if (!$result || mysqli_num_rows($result) === 0) {
            return null;
        }

        return mysqli_fetch_assoc($result);
    }
}

if (!function_exists('slaDiscountCalculateDiscount')) {
    /**
     * Calculate discount percentage from SLA percentage
     * 
     * @param float $slaPercent SLA percentage (0-100)
     * @return float Discount percentage (0-100)
     */
    function slaDiscountCalculateDiscount(float $slaPercent): float
    {
        // Ensure SLA is within 0-100
        $slaPercent = max(0, min(100, $slaPercent));
        
        // Discount = 100 - SLA
        $discountPercent = 100 - $slaPercent;
        
        // Apply max discount cap from config
        $config = slaDiscountGetConfig();
        $maxDiscount = $config['max_discount_percent'] ?? 100;
        $discountPercent = min($discountPercent, $maxDiscount);
        
        return round($discountPercent, 2);
    }
}

if (!function_exists('slaDiscountCalculateAmount')) {
    /**
     * Calculate discounted amount
     * 
     * @param float $originalAmount Original invoice amount
     * @param float $discountPercent Discount percentage
     * @return array Array with original, discount amount, and final total
     */
    function slaDiscountCalculateAmount(float $originalAmount, float $discountPercent): array
    {
        $discountAmount = round(($originalAmount * $discountPercent) / 100, 2);
        $finalAmount = round($originalAmount - $discountAmount, 2);

        return [
            'original_amount' => round($originalAmount, 2),
            'discount_percent' => round($discountPercent, 2),
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'savings' => $discountAmount
        ];
    }
}

if (!function_exists('calculateInvoiceWithSlaDiscount')) {
    /**
     * Calculate complete invoice breakdown with SLA discount
     * 
     * @param mysqli $conn Database connection
     * @param string $idpel Customer ID (IDPEL)
     * @param string $pemilik Owner/Server name
     * @param float $originalAmount Original invoice amount
     * @return array Complete breakdown with SLA data and discount
     */
    function calculateInvoiceWithSlaDiscount(mysqli $conn, string $idpel, string $pemilik, float $originalAmount): array
    {
        $result = [
            'has_discount' => false,
            'sla_data' => null,
            'discount_percent' => 0,
            'original_amount' => round($originalAmount, 2),
            'discount_amount' => 0,
            'final_amount' => round($originalAmount, 2),
            'savings' => 0
        ];

        // Check if feature is enabled
        if (!slaDiscountIsEnabled()) {
            return $result;
        }

        // Get customer SLA data
        $slaData = slaDiscountGetCustomerSlaData($conn, $idpel, $pemilik);
        if (!$slaData) {
            return $result;
        }

        // Get SLA percentage
        $slaPercent = (float)($slaData['total_sla_percent'] ?? 0);
        if ($slaPercent <= 0) {
            return $result;
        }

        // Calculate discount from SLA
        $discountPercent = slaDiscountCalculateDiscount($slaPercent);
        if ($discountPercent <= 0) {
            return $result;
        }

        // Calculate discount amounts
        $amounts = slaDiscountCalculateAmount($originalAmount, $discountPercent);

        return [
            'has_discount' => true,
            'sla_data' => $slaData,
            'discount_percent' => $discountPercent,
            'original_amount' => $amounts['original_amount'],
            'discount_amount' => $amounts['discount_amount'],
            'final_amount' => $amounts['final_amount'],
            'savings' => $amounts['savings'],
            'sla_percent' => round($slaPercent, 2),
            'snapshot_month' => $slaData['snapshot_month'] ?? null,
            'snapshot_date' => $slaData['snapshot_date'] ?? null
        ];
    }
}

if (!function_exists('renderSlaDiscountBreakdown')) {
    /**
     * Render HTML breakdown of SLA discount
     * 
     * @param array $breakdown Breakdown data from calculateInvoiceWithSlaDiscount()
     * @return string HTML markup
     */
    function renderSlaDiscountBreakdown(array $breakdown): string
    {
        if (!$breakdown['has_discount'] || !$breakdown['sla_data']) {
            return '';
        }

        $sla = $breakdown['sla_data'];
        $slaPercent = $breakdown['sla_percent'] ?? 0;
        $discountPercent = $breakdown['discount_percent'] ?? 0;
        $original = number_format($breakdown['original_amount'], 2, ',', '.');
        $discount = number_format($breakdown['discount_amount'], 2, ',', '.');
        $final = number_format($breakdown['final_amount'], 2, ',', '.');
        $savings = number_format($breakdown['savings'], 2, ',', '.');
        $month = $breakdown['snapshot_month'] ?? 'N/A';

        return <<<HTML
<div class="sla-discount-breakdown mt-3">
    <h6 class="sla-breakdown-title">📊 Breakdown Diskon SLA Komitmen</h6>
    <table class="table table-sm table-borderless">
        <tbody>
            <tr class="sla-row-base">
                <td><strong>Tagihan Asli</strong></td>
                <td class="text-end"><strong>Rp {$original}</strong></td>
            </tr>
            <tr class="sla-row-sla">
                <td><small>SLA Bulan {$month}</small></td>
                <td class="text-end"><small><strong>{$slaPercent}%</strong> Uptime</small></td>
            </tr>
            <tr class="sla-row-discount">
                <td><small>💰 Diskon Komitmen SLA</small></td>
                <td class="text-end"><small><strong>-{$discountPercent}%</strong> = Rp {$discount}</small></td>
            </tr>
            <tr class="sla-row-total">
                <td><strong>✅ Total yang Harus Dibayar</strong></td>
                <td class="text-end"><strong>Rp {$final}</strong></td>
            </tr>
            <tr>
                <td colspan="2"><small class="sla-info-footer">💚 Anda hemat <strong>Rp {$savings}</strong> berdasarkan komitmen SLA</small></td>
            </tr>
        </tbody>
    </table>
</div>
HTML;
    }
}

if (!function_exists('slaDiscountSaveConfig')) {
    /**
     * Save SLA discount configuration
     * 
     * @param array $config Configuration array
     * @return bool Success status
     */
    function slaDiscountSaveConfig(array $config): bool
    {
        $configFile = __DIR__ . '/../notifbot/data/sla_discount_config.json';
        @mkdir(dirname($configFile), 0755, true);

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return file_put_contents($configFile, $encoded) !== false;
    }
}

// Initialize config file if it doesn't exist
if (!file_exists(__DIR__ . '/../notifbot/data/sla_discount_config.json')) {
    slaDiscountGetConfig();
}
