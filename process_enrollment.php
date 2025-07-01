<?php
/**
 * Enhanced Process Enrollment with Excess Payment Handling
 * CarePro POS System - Complete Implementation
 * User: Scraper001 | Time: 2025-06-21 09:18:55
 */

include '../../../connection/connection.php';
$conn = con();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Enhanced error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Safe float conversion with validation
 */
function safe_float($value)
{
    if (is_null($value) || $value === '')
        return 0.0;
    return floatval($value);
}

/**
 * Debug logging function
 */
function debug_log($message, $data = null)
{
    error_log("[" . date('Y-m-d H:i:s') . "] " . $message . ($data ? " Data: " . json_encode($data) : ""));
}

/**
 * ENHANCED: Excess Payment Handler for Initial Payments
 */
function handleExcessInitialPayment($conn, $student_id, $program_id, $excess_option, $original_amount, $required_amount, $excess_amount, $total_tuition, $start_balance)
{
    $result = [
        'processed_amount' => $required_amount,
        'change_amount' => $excess_amount,
        'description' => '',
        'demo_allocations' => [],
        'final_balance' => null
    ];

    switch ($excess_option) {
        case 'treat_as_full':
            // 1️⃣ Treat Payment as Full Initial
            $remaining_tuition = $total_tuition - $original_amount;
            $demo_balance = max(0, $remaining_tuition / 4);

            $result = [
                'processed_amount' => $original_amount,
                'change_amount' => 0,
                'description' => "Payment treated as full initial. Demo balance reduced to ₱" . number_format($demo_balance, 2) . " each.",
                'demo_allocations' => [
                    'demo1' => $demo_balance,
                    'demo2' => $demo_balance,
                    'demo3' => $demo_balance,
                    'demo4' => $demo_balance
                ],
                'final_balance' => $start_balance - $original_amount
            ];
            break;

        case 'allocate_to_demos':
            // 2️⃣ Allocate Excess to Demos (Fixed)
            // First calculate the per-demo fee correctly
            $base_demo_fee = ($total_tuition - $required_amount) / 4;
            $remaining_excess = $excess_amount;
            $demo_allocations = [];

            // Check existing demo payments
            $existing_demos = [];
            $stmt = $conn->prepare("SELECT demo_type, SUM(cash_received) as paid FROM pos_transactions WHERE student_id = ? AND program_id = ? AND payment_type = 'demo_payment' GROUP BY demo_type");
            $stmt->bind_param("ii", $student_id, $program_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $existing_demos[$row['demo_type']] = safe_float($row['paid']);
            }
            $stmt->close();

            // Start from the current balance after initial payment
            $running_balance = $start_balance - $required_amount;

            // Allocate excess to demos in order
            for ($i = 1; $i <= 4; $i++) {
                if ($remaining_excess <= 0)
                    break;

                $demo_type = "demo{$i}";
                $already_paid = isset($existing_demos[$demo_type]) ? $existing_demos[$demo_type] : 0.0;

                // Calculate how much more this demo needs
                $demo_remaining = $base_demo_fee - $already_paid;

                if ($demo_remaining > 0) {
                    // Only allocate up to what's needed for this demo
                    $allocation = min($remaining_excess, $demo_remaining);

                    if ($allocation > 0) {
                        $running_balance -= $allocation;

                        // Insert the demo payment transaction
                        $stmt = $conn->prepare(
                            "INSERT INTO pos_transactions 
                    (student_id, program_id, payment_type, demo_type, cash_received, balance, description, status, processed_by, transaction_date)
                    VALUES (?, ?, 'demo_payment', ?, ?, ?, 'Excess allocation from initial payment', 'Active', 'scrapper22', '2025-06-29 22:52:04')"
                        );
                        $stmt->bind_param("iisdd", $student_id, $program_id, $demo_type, $allocation, $running_balance);
                        $stmt->execute();
                        $stmt->close();

                        // Update remaining excess
                        $remaining_excess -= $allocation;
                    }

                    // Record allocation details
                    $demo_allocations[$demo_type] = [
                        'allocated' => $allocation,
                        'already_paid' => $already_paid,
                        'remaining' => $demo_remaining - $allocation,
                        'status' => ($already_paid + $allocation >= $base_demo_fee) ? 'Paid in Full' : 'Partially Paid'
                    ];
                }
            }

            $result = [
                'processed_amount' => $required_amount,
                'change_amount' => $remaining_excess, // Return any unallocated excess as change
                'description' => "Initial payment of ₱" . number_format($required_amount, 2) . " applied. Excess allocated to demos.",
                'demo_allocations' => $demo_allocations,
                'final_balance' => $running_balance
            ];
            break;
        case 'return_as_change':
        default:
            // 3️⃣ Return the Excess as Change
            $result = [
                'processed_amount' => $required_amount,
                'change_amount' => $excess_amount,
                'description' => "Only required initial amount processed. ₱" . number_format($excess_amount, 2) . " returned as change.",
                'demo_allocations' => [],
                'final_balance' => $start_balance - $required_amount
            ];
            break;
    }

    return $result;
}

/**
 * ENHANCED: Excess Payment Handler for Demo Payments
 */
function handleExcessDemoPayment($conn, $student_id, $program_id, $excess_option, $original_amount, $required_amount, $excess_amount, $current_demo)
{
    $result = [
        'processed_amount' => $required_amount,
        'change_amount' => $excess_amount,
        'description' => '',
        'demo_allocations' => [],
        'final_balance' => null
    ];

    // Get current balance
    $balance_stmt = $conn->prepare("
        SELECT balance FROM pos_transactions 
        WHERE student_id = ? AND program_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $balance_stmt->bind_param("ii", $student_id, $program_id);
    $balance_stmt->execute();
    $balance_result = $balance_stmt->get_result()->fetch_assoc();
    $start_balance = safe_float($balance_result['balance']);

    switch ($excess_option) {
        case 'add_to_next_demo':
            // Complete current demo first
            $running_balance = $start_balance - $required_amount;
            $demo_allocations = [];

            // Record current demo payment
            $stmt = $conn->prepare("
                INSERT INTO pos_transactions 
                (student_id, program_id, payment_type, demo_type, cash_received, balance, description, status, processed_by, transaction_date)
                VALUES (?, ?, 'demo_payment', ?, ?, ?, 'Full payment for current demo', 'Active', 'scrapper22', '2025-06-29 22:49:23')
            ");
            $stmt->bind_param("iisdd", $student_id, $program_id, $current_demo, $required_amount, $running_balance);
            $stmt->execute();
            $stmt->close();

            // Find and allocate to next demo
            $next_demo = findNextUnpaidDemo($conn, $student_id, $program_id, $current_demo);
            if ($next_demo) {
                $running_balance -= $excess_amount;

                $stmt = $conn->prepare("
                    INSERT INTO pos_transactions 
                    (student_id, program_id, payment_type, demo_type, cash_received, balance, description, status, processed_by, transaction_date)
                    VALUES (?, ?, 'demo_payment', ?, ?, ?, 'Excess allocation from previous demo', 'Active', 'scrapper22', '2025-06-29 22:49:23')
                ");
                $stmt->bind_param("iisdd", $student_id, $program_id, $next_demo, $excess_amount, $running_balance);
                $stmt->execute();
                $stmt->close();

                $demo_allocations = [
                    $current_demo => [
                        'allocated' => $required_amount,
                        'status' => 'Paid in Full'
                    ],
                    $next_demo => [
                        'allocated' => $excess_amount,
                        'status' => 'Partially Paid'
                    ]
                ];

                $result = [
                    'processed_amount' => $original_amount,
                    'change_amount' => 0,
                    'description' => "Demo {$current_demo} paid in full. Excess ₱" . number_format($excess_amount, 2) . " allocated to {$next_demo}.",
                    'demo_allocations' => $demo_allocations,
                    'final_balance' => $running_balance
                ];
            }
            break;

        case 'credit_to_account':
            // Process current demo only
            $running_balance = $start_balance - $required_amount;

            $stmt = $conn->prepare("
                INSERT INTO pos_transactions 
                (student_id, program_id, payment_type, demo_type, cash_received, balance, description, status, processed_by, transaction_date)
                VALUES (?, ?, 'demo_payment', ?, ?, ?, 'Demo payment with credit to account', 'Active', 'scrapper22', '2025-06-29 22:49:23')
            ");
            $stmt->bind_param("iisdd", $student_id, $program_id, $current_demo, $required_amount, $running_balance);
            $stmt->execute();
            $stmt->close();

            // Record credit to account
            $credit_balance = $running_balance - $excess_amount;
            $stmt = $conn->prepare("
                INSERT INTO pos_transactions 
                (student_id, program_id, payment_type, cash_received, balance, description, status, processed_by, transaction_date)
                VALUES (?, ?, 'credit', ?, ?, 'Credit from demo payment excess', 'Active', 'scrapper22', '2025-06-29 22:49:23')
            ");
            $stmt->bind_param("iidd", $student_id, $program_id, $excess_amount, $credit_balance);
            $stmt->execute();
            $stmt->close();

            $result = [
                'processed_amount' => $original_amount,
                'change_amount' => 0,
                'description' => "Demo payment processed. ₱" . number_format($excess_amount, 2) . " credited to account.",
                'demo_allocations' => [
                    $current_demo => [
                        'allocated' => $required_amount,
                        'status' => 'Paid in Full'
                    ]
                ],
                'final_balance' => $credit_balance
            ];
            break;

        case 'return_as_change':
        default:
            // Process only required amount and return excess as change
            $running_balance = $start_balance - $required_amount;

            $stmt = $conn->prepare("
                INSERT INTO pos_transactions 
                (student_id, program_id, payment_type, demo_type, cash_received, balance, description, status, processed_by, transaction_date)
                VALUES (?, ?, 'demo_payment', ?, ?, ?, 'Demo payment with change returned', 'Active', 'scrapper22', '2025-06-29 22:49:23')
            ");
            $stmt->bind_param("iisdd", $student_id, $program_id, $current_demo, $required_amount, $running_balance);
            $stmt->execute();
            $stmt->close();

            $result = [
                'processed_amount' => $required_amount,
                'change_amount' => $excess_amount,
                'description' => "Demo payment processed. ₱" . number_format($excess_amount, 2) . " returned as change.",
                'demo_allocations' => [
                    $current_demo => [
                        'allocated' => $required_amount,
                        'status' => 'Paid in Full'
                    ]
                ],
                'final_balance' => $running_balance
            ];
            break;
    }

    return $result;
}

function getCurrentBalance($conn, $student_id, $program_id)
{
    $stmt = $conn->prepare("
        SELECT balance 
        FROM pos_transactions 
        WHERE student_id = ? AND program_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param("ii", $student_id, $program_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return floatval($result['balance'] ?? 0);
}

/**
 * Find next unpaid demo
 */
function findNextUnpaidDemo($conn, $student_id, $program_id, $current_demo)
{
    $demos = ['demo1', 'demo2', 'demo3', 'demo4'];
    $current_index = array_search($current_demo, $demos);

    if ($current_index === false)
        return null;

    for ($i = $current_index + 1; $i < count($demos); $i++) {
        $check_demo = $demos[$i];

        // Check if demo is unpaid
        $stmt = $conn->prepare("SELECT id FROM pos_transactions WHERE student_id = ? AND program_id = ? AND demo_type = ?");
        $stmt->bind_param("iis", $student_id, $program_id, $check_demo);
        $stmt->execute();

        if ($stmt->get_result()->num_rows == 0) {
            return $check_demo;
        }
    }

    return null;
}

/**
 * Check if program has ended or is ending soon
 */
function checkProgramEndDate($conn, $program_id)
{
    $stmt = $conn->prepare("SELECT program_name, end_date FROM program WHERE id = ?");
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if (!$result) {
        return ['status' => 'NOT_FOUND', 'message' => 'Program not found'];
    }

    if (!$result['end_date']) {
        return ['status' => 'ACTIVE', 'message' => 'No end date set'];
    }

    $end_date = new DateTime($result['end_date']);
    $today = new DateTime();
    $today->setTime(0, 0, 0);

    if ($end_date < $today) {
        return [
            'status' => 'ENDED',
            'message' => 'ENROLLMENT BLOCKED: Program "' . $result['program_name'] . '" has ended on ' . $end_date->format('Y-m-d'),
            'program_name' => $result['program_name'],
            'end_date' => $result['end_date']
        ];
    }

    if ($end_date->format('Y-m-d') === $today->format('Y-m-d')) {
        return [
            'status' => 'ENDING_TODAY',
            'message' => 'WARNING: Program "' . $result['program_name'] . '" ends today!',
            'program_name' => $result['program_name'],
            'end_date' => $result['end_date']
        ];
    }

    $days_until_end = $today->diff($end_date)->days;
    if ($days_until_end <= 7) {
        return [
            'status' => 'ENDING_SOON',
            'message' => 'Notice: Program "' . $result['program_name'] . '" ends in ' . $days_until_end . ' days',
            'program_name' => $result['program_name'],
            'end_date' => $result['end_date'],
            'days_remaining' => $days_until_end
        ];
    }

    return ['status' => 'ACTIVE', 'message' => 'Program is active'];
}

/**
 * Calculate demo fee for a student
 */
function calculateDemoFee($conn, $student_id, $program_id, $final_total)
{
    // Get current balance from most recent transaction
    $balance_stmt = $conn->prepare("
        SELECT balance FROM pos_transactions 
        WHERE student_id = ? AND program_id = ? 
        ORDER BY id DESC LIMIT 1
    ");

    if (!$balance_stmt) {
        debug_log("❌ Failed to prepare balance statement", $conn->error);
        return 0;
    }

    $balance_stmt->bind_param("ii", $student_id, $program_id);
    $balance_stmt->execute();
    $balance_result = $balance_stmt->get_result()->fetch_assoc();
    $current_balance = safe_float($balance_result['balance'] ?? $final_total);

    // Get paid demos count
    $demo_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT demo_type) as paid_demos_count 
        FROM pos_transactions 
        WHERE student_id = ? AND program_id = ? 
        AND payment_type = 'demo_payment' 
        AND demo_type IS NOT NULL 
        AND demo_type != ''
    ");
    $demo_stmt->bind_param("ii", $student_id, $program_id);
    $demo_stmt->execute();
    $demo_result = $demo_stmt->get_result()->fetch_assoc();
    $paid_demos_count = intval($demo_result['paid_demos_count'] ?? 0);

    $remaining_demos = 4 - $paid_demos_count;
    $demo_fee = $remaining_demos > 0 ? max(0, $current_balance / $remaining_demos) : 0;

    return $demo_fee;
}

$current_time = date('Y-m-d H:i:s');

try {
    // Get POST data
    $student_id = $_POST['student_id'];
    $program_id = $_POST['program_id'];
    $learning_mode = $_POST['learning_mode'];
    $payment_type = $_POST['type_of_payment'];
    $package_name = $_POST['package_id'] ?? 'Regular';
    $demo_type = $_POST['demo_type'] ?? null;
    $selected_schedules = $_POST['selected_schedules'] ?? null;

    // ENHANCED: Get excess payment data
    $has_excess_payment = isset($_POST['has_excess_payment']) && $_POST['has_excess_payment'] === 'true';
    $excess_choice = $_POST['excess_choice'] ?? null;
    $excess_amount = safe_float($_POST['excess_amount'] ?? 0);
    $excess_allocations = $_POST['excess_allocations'] ?? '[]';
    $original_payment_amount = safe_float($_POST['original_payment_amount'] ?? 0);
    $required_payment_amount = safe_float($_POST['required_payment_amount'] ?? 0);

    debug_log("💰 Excess Payment Data", [
        'has_excess' => $has_excess_payment,
        'choice' => $excess_choice,
        'excess_amount' => $excess_amount,
        'original_payment' => $original_payment_amount,
        'required_payment' => $required_payment_amount
    ]);

    // Real-time program end date validation
    $program_info = checkProgramEndDate($conn, $program_id);

    // Validate schedule selection for initial payments
    if ($payment_type === 'initial_payment') {
        $schedules_data = json_decode($selected_schedules, true);
        if (!is_array($schedules_data) || empty($schedules_data)) {
            // Check if there are maintained schedules from previous transaction
            $maintained_stmt = $conn->prepare("
                SELECT selected_schedules 
                FROM pos_transactions 
                WHERE student_id = ? AND program_id = ? 
                AND selected_schedules IS NOT NULL 
                AND selected_schedules != '' 
                AND selected_schedules != '[]'
                ORDER BY id ASC LIMIT 1
            ");
            $maintained_stmt->bind_param("ii", $student_id, $program_id);
            $maintained_stmt->execute();
            $maintained_result = $maintained_stmt->get_result()->fetch_assoc();

            if (!$maintained_result || empty($maintained_result['selected_schedules'])) {
                throw new Exception("Schedule selection is required for initial payments");
            }

            debug_log("✅ Using maintained schedules for initial payment");
        }
    }

    // Get existing balance and payment info
    $balance_info_stmt = $conn->prepare("
        SELECT subtotal, total_amount, balance, promo_discount, 
               learning_mode, package_name, selected_schedules, system_fee
        FROM pos_transactions 
        WHERE student_id = ? AND program_id = ? 
        ORDER BY id DESC LIMIT 1
    ");

    $balance_info_stmt->bind_param("ii", $student_id, $program_id);
    $balance_info_stmt->execute();
    $balance_info_result = $balance_info_stmt->get_result();

    $current_balance = 0;
    $current_total = 0;
    $current_subtotal = 0;
    $existing_promo_discount = 0;
    $learning_mode_from_db = $learning_mode;
    $package_name_from_db = $package_name;
    $existing_selected_schedules = $selected_schedules;
    $system_fee = 0;

    if ($balance_info_result->num_rows > 0) {
        $balance_info = $balance_info_result->fetch_assoc();
        $current_balance = safe_float($balance_info['balance']);
        $current_total = safe_float($balance_info['total_amount']);
        $current_subtotal = safe_float($balance_info['subtotal']);
        $existing_promo_discount = safe_float($balance_info['promo_discount']);
        $learning_mode_from_db = $balance_info['learning_mode'];
        $package_name_from_db = $balance_info['package_name'];
        $existing_selected_schedules = $balance_info['selected_schedules'];
        $system_fee = safe_float($balance_info['system_fee']);
    }

    // Get payment amount from form
    $payment_amount = safe_float($_POST['total_payment'] ?? 0);
    $cash = safe_float($_POST['cash'] ?? 0);

    // Get computation values from POST
    $subtotal = safe_float($_POST['sub_total'] ?? 0);
    $final_total = safe_float($_POST['final_total'] ?? 0);
    $promo_discount = safe_float($_POST['promo_applied'] ?? 0);

    // Validate required values
    if ($subtotal <= 0 && !$current_subtotal) {
        throw new Exception("Invalid subtotal amount");
    }

    if ($final_total <= 0 && !$current_total) {
        throw new Exception("Invalid final total amount");
    }

    // Use existing values if this is a continuing enrollment
    if ($current_total > 0) {
        $final_total = $current_total;
        $subtotal = $current_subtotal;
        $promo_discount = $existing_promo_discount;
        $learning_mode = $learning_mode_from_db;
        $package_name = $package_name_from_db;
        $selected_schedules = $existing_selected_schedules;
    }

    // ENHANCED: Process excess payment if applicable
    $excess_processing_result = null;
    $final_payment_amount = $payment_amount;
    $final_change_amount = max(0, $cash - $payment_amount);
    $start_balance = $current_balance > 0 ? $current_balance : $final_total;

    if ($has_excess_payment && $excess_choice) {
        if ($payment_type === 'initial_payment') {
            $excess_processing_result = handleExcessInitialPayment(
                $conn,
                $student_id,
                $program_id,
                $excess_choice,
                $original_payment_amount,
                $required_payment_amount,
                $excess_amount,
                $final_total,
                $start_balance
            );
        } elseif ($payment_type === 'demo_payment') {
            $excess_processing_result = handleExcessDemoPayment(
                $conn,
                $student_id,
                $program_id,
                $excess_choice,
                $original_payment_amount,
                $required_payment_amount,
                $excess_amount,
                $demo_type
            );
        }

        if ($excess_processing_result) {
            $final_payment_amount = $excess_processing_result['processed_amount'];
            $final_change_amount = max(0, ($cash - $final_payment_amount) + $excess_processing_result['change_amount']);
        }
    }

    $balance = $start_balance - $final_payment_amount;

    // If excess was processed and allocated, the final balance for the response is different
    $response_balance = $balance;
    if ($excess_processing_result && isset($excess_processing_result['final_balance']) && !is_null($excess_processing_result['final_balance'])) {
        $response_balance = $excess_processing_result['final_balance'];
    }

    // Validate payment
    if ($cash < $final_payment_amount) {
        throw new Exception("Insufficient cash payment. Required: ₱" . number_format($final_payment_amount, 2) . ", Received: ₱" . number_format($cash, 2));
    }

    $enrollment_status = in_array($payment_type, ['full_payment', 'demo_payment', 'initial_payment']) ? 'Enrolled' : 'Reserved';

    // Get system fee if online
    if ($learning_mode === 'Online' && !$system_fee) {
        $program_stmt = $conn->prepare("SELECT system_fee FROM program WHERE id = ?");
        $program_stmt->bind_param("i", $program_id);
        $program_stmt->execute();
        $program_result = $program_stmt->get_result()->fetch_assoc();
        $system_fee = safe_float($program_result['system_fee'] ?? 0);
    }

    // Create description with excess payment info
    $description = "Payment processed - " . ucfirst(str_replace('_', ' ', $payment_type));
    if ($excess_processing_result) {
        $description .= " (Excess: " . $excess_processing_result['description'] . ")";
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // ENHANCED: Program end date validation with blocking
        if ($program_info['status'] === 'ENDED') {
            throw new Exception($program_info['message']);
        }




        // FIXED: Insert POS transaction with correct field mapping
        $sql = "INSERT INTO pos_transactions (
            student_id, program_id, learning_mode, package_name, payment_type, 
            demo_type, selected_schedules, subtotal, promo_discount, system_fee, 
            total_amount, cash_received, change_amount, balance, enrollment_status,
            debit_amount, credit_amount, change_given, description, status,
            processed_by, transaction_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 'Scraper001', NOW())";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $debit_amount = $final_payment_amount;
        $credit_amount = $final_payment_amount;



        if ($excess_choice == "allocate_to_demos") {
            $change_given = 0;
            $final_change_amount = 0;
        } else {
            $change_given = $final_change_amount;
        }




        $stmt->bind_param(
            "iisssssdddddddsddds",
            $student_id,
            $program_id,
            $learning_mode,
            $package_name,
            $payment_type,
            $demo_type,
            $selected_schedules,
            $subtotal,
            $promo_discount,
            $system_fee,
            $final_total,
            $final_payment_amount,
            $final_change_amount,
            $balance,
            $enrollment_status,
            $debit_amount,
            $credit_amount,

            $change_given,
            $description
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert transaction: " . $stmt->error);
        }

        $transaction_id = $stmt->insert_id;

        // ENHANCED: Log excess payment processing if applicable
        if ($excess_processing_result && isset($excess_processing_result['demo_allocations'])) {
            $excess_data = [
                'choice' => $excess_choice,
                'excess_amount' => $excess_amount,
                'allocations' => $excess_processing_result['demo_allocations'],
                'description' => $excess_processing_result['description'],
                'processed_at' => $current_time,
                'processed_by' => 'Scraper001'
            ];

            // Check if excess_processing_log table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'excess_processing_log'");
            if ($table_check->num_rows > 0) {
                $log_stmt = $conn->prepare("
                    INSERT INTO excess_processing_log (
                        transaction_id, student_id, program_id, excess_amount,
                        excess_choice, allocations_data, final_change_amount,
                        processed_at, processed_by, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Scraper001', ?)
                ");

                $allocations_json = json_encode($excess_processing_result['demo_allocations']);
                $notes = $excess_processing_result['description'];

                $log_stmt->bind_param(
                    "iiiisdds",
                    $transaction_id,
                    $student_id,
                    $program_id,
                    $excess_amount,
                    $excess_choice,
                    $allocations_json,
                    $excess_processing_result['change_amount'],
                    $notes
                );
                $log_stmt->execute();
            }
        }

        // Insert/Update Student Enrollment
        $enrollment_check = $conn->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND program_id = ?");
        $enrollment_check->bind_param("ii", $student_id, $program_id);
        $enrollment_check->execute();
        $existing_enrollment = $enrollment_check->get_result()->fetch_assoc();

        if ($existing_enrollment) {
            // Update existing enrollment
            $update_enrollment = $conn->prepare("
                UPDATE student_enrollments 
                SET pos_transaction_id = ?, status = ?, selected_schedules = ?, updated_at = NOW()
                WHERE student_id = ? AND program_id = ?
            ");
            $update_enrollment->bind_param("issii", $transaction_id, $enrollment_status, $selected_schedules, $student_id, $program_id);
            $update_enrollment->execute();
        } else {
            // Insert new enrollment
            $enrollment_stmt = $conn->prepare("
                INSERT INTO student_enrollments (
                    student_id, program_id, pos_transaction_id, status, 
                    learning_mode, selected_schedules
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $enrollment_stmt->bind_param("iiisss", $student_id, $program_id, $transaction_id, $enrollment_status, $learning_mode, $selected_schedules);
            $enrollment_stmt->execute();
        }

        // Update student status
        $student_stmt = $conn->prepare("UPDATE student_info_tbl SET enrollment_status = ? WHERE id = ?");
        $student_stmt->bind_param("si", $enrollment_status, $student_id);
        $student_stmt->execute();

        $conn->commit();

        debug_log("✅ Transaction completed successfully", [
            'transaction_id' => $transaction_id,
            'student_id' => $student_id,
            'payment_type' => $payment_type,
            'amount' => $final_payment_amount,
            'balance' => $response_balance,
            'excess_handled' => $excess_choice ?? 'none'
        ]);

        // ENHANCED: Prepare response with excess payment information
        $response_message = 'Payment processed successfully';
        $warnings = [];

        if ($program_info['status'] === 'ENDING_TODAY') {
            $warnings[] = 'Program ends today (' . date('F j, Y', strtotime($program_info['end_date'])) . ')';
        } elseif (isset($program_info['days_remaining']) && $program_info['days_remaining'] <= 7) {
            $warnings[] = 'Program ends in ' . $program_info['days_remaining'] . ' days (' . date('F j, Y', strtotime($program_info['end_date'])) . ')';
        }

        if (!empty($warnings)) {
            $response_message .= ' - NOTE: ' . implode(', ', $warnings);
        }

        if ($excess_processing_result) {
            $response_message .= ' with excess payment handling (' . $excess_choice . ')';
        }

        $response = [
            'success' => true,
            'message' => $response_message,
            'transaction_id' => $transaction_id,
            'enrollment_status' => $enrollment_status,
            'balance' => $response_balance,
            'subtotal' => $subtotal,
            'promo_discount' => $promo_discount,
            'total_amount' => $final_total,
            'payment_amount' => $final_payment_amount,
            'cash_received' => $final_payment_amount,
            'total_cash_given' => $cash,
            'change_amount' => $final_change_amount,
            'credit_amount' => $credit_amount,
            'program_end_check' => [
                'status' => $program_info['status'],
                'end_date' => $program_info['end_date'] ?? null,
                'program_name' => $program_info['program_name'] ?? '',
                'days_remaining' => $program_info['days_remaining'] ?? null,
                'current_date' => date('Y-m-d')
            ],
            'timestamp' => $current_time,
            'processed_by' => 'Scraper001'
        ];

        // Add excess processing information to response
        if ($excess_processing_result) {
            $response['excess_processing'] = [
                'success' => true,
                'choice' => $excess_choice,
                'excess_amount' => $excess_amount,
                'final_change_amount' => $excess_processing_result['change_amount'],
                'processing_notes' => [$excess_processing_result['description']],
                'processed_at' => $current_time,
                'processed_by' => 'Scraper001'
            ];

            if (isset($excess_processing_result['demo_allocations'])) {
                $response['excess_processing']['allocations'] = $excess_processing_result['demo_allocations'];
            }
        }

        echo json_encode($response);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    debug_log("❌ Enrollment processing error", $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'timestamp' => $current_time,
        'processed_by' => 'Scraper001'
    ]);
}

$conn->close();
?>