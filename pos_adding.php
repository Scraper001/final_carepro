<?php
include "../connection/connection.php";
include "includes/header.php";
$conn = con();

$student = null;
$open = true;

// System information
$current_timestamp = '2025-06-29 22:31:44'; // Current timestamp
$current_user = 'scrapper22'; // Current user

$current_package = null;
if (isset($_GET['student_id'])) {
    // Get the latest package info from pos_transactions
    $stmt = $conn->prepare("SELECT pt.package_name, p.* 
        FROM pos_transactions pt 
        LEFT JOIN promo p ON pt.package_name = p.package_name 
        WHERE pt.student_id = ? 
        ORDER BY pt.id DESC LIMIT 1");
    $stmt->bind_param("i", $_GET['student_id']);
    $stmt->execute();
    $package_result = $stmt->get_result();
    if ($package_result->num_rows > 0) {
        $current_package = $package_result->fetch_assoc();
    }
    $stmt->close();
}

function hasExistingEnrollment($conn, $student_id)
{
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM pos_transactions WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['count'] > 0;
}

// Check enrollment status
$enrollment_locked = false;
if (isset($_GET['student_id'])) {
    $enrollment_locked = hasExistingEnrollment($conn, $_GET['student_id']);
}

if (isset($_GET['student_id'])) {
    $student_id = intval($_GET['student_id']);

    // Get student info
    $stmt = $conn->prepare("SELECT * FROM student_info_tbl WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    // Check if student has existing balance
    $stmt = $conn->prepare("SELECT SUM(balance) as total_balance FROM pos_transactions WHERE student_id = ? AND balance > 0");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $balance_result = $stmt->get_result()->fetch_assoc();
    $has_balance = $balance_result['total_balance'] > 0;
    $stmt->close();

    // Get all transactions for this student
    $stmt = $conn->prepare("SELECT * FROM pos_transactions WHERE student_id = ? ORDER BY id DESC");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $transaction_Result = $stmt->get_result();



    $transactions = [];

    if ($transaction_Result->num_rows > 0) {


        while ($row = $transaction_Result->fetch_assoc()) {
            $transactions[] = $row;
        }

        $row_transaction = $transactions[0]; // Most recent transaction
        $open = false;

        // Get program details
        $stmt = $conn->prepare("SELECT * FROM program WHERE id = ?");
        $stmt->bind_param("i", $row_transaction['program_id']);
        $stmt->execute();
        $program_results = $stmt->get_result();

        if ($program_results->num_rows > 0) {
            $row_program = $program_results->fetch_assoc();
            $program = $row_program['program_name'];
            $program_id = $row_program['id']; // Set program_id here
        } else {
            $program = "THIS PROGRAM IS REMOVED";
            $row_program = ['id' => 0, 'total_tuition' => 0];
            $program_id = 0;
        }
        $stmt->close();

        // Get promo details
        $stmt = $conn->prepare("SELECT * FROM promo WHERE program_id = ?");
        $stmt->bind_param("i", $row_program['id']);
        $stmt->execute();
        $promo_result = $stmt->get_result();

        if ($promo_result->num_rows > 0) {
            $row_promo = $promo_result->fetch_assoc();
        } else {
            $row_promo = [
                'package_name' => 'Regular',
                'enrollment_fee' => 0,
                'percentage' => 0,
                'promo_type' => 'none',
                'selection_type' => 1,
                'custom_initial_payment' => null
            ];
        }
        $stmt->close();

        // Calculate promo discount
        $TT = floatval($row_program['total_tuition'] ?? 0);
        $PR = 0;
        if ($row_promo['package_name'] !== "Regular" && $row_promo['promo_type'] !== 'none') {
            $selection_type = intval($row_promo['selection_type'] ?? 1);

            if ($selection_type <= 2) {
                // Options 1-2: Percentage calculation
                if ($row_promo['promo_type'] === 'percentage') {
                    $PR = $TT * (floatval($row_promo['percentage']) / 100);
                } else {
                    $PR = floatval($row_promo['enrollment_fee']);
                }
            } else {
                // Options 3-4: Custom payment (discount is already in enrollment_fee)
                $PR = floatval($row_promo['enrollment_fee']);
            }
        }

        // Existing payment info
        $payment_counts = [
            'initial_payment' => 0,
            'reservation' => 0,
            'demo_payment' => 0,
            'full_payment' => 0
        ];

        $IP = 0;
        $R = 0;

        $final_total = $TT - $PR;

        // Get initial payment and balance info
        $result_balance = $conn->query("SELECT * FROM `pos_transactions` WHERE student_id = '$student_id' ORDER BY `pos_transactions`.`id` DESC");
        $row_balance_total = $result_balance->fetch_assoc();
        $balance_total = $row_balance_total['balance'];

        $result_balance2 = $conn->query("SELECT * FROM `pos_transactions` WHERE student_id = '$student_id' AND payment_type = 'initial_payment' ORDER BY `pos_transactions`.`id` DESC");
        $row_balance_total2 = $result_balance2->fetch_assoc();
        $balance_total2 = $row_balance_total2['balance'];

        $initial_pay = isset($row_balance_total2['credit_amount']) ? $row_balance_total2['credit_amount'] : 0;

        // Get program total
        $select_programs_total = "SELECT * FROM program WHERE id = '" . $row_balance_total['program_id'] . "'";
        $select_programs_total_result = $conn->query($select_programs_total);
        $select_program_row = $select_programs_total_result->fetch_assoc();
        $total_tuition = $select_program_row['total_tuition'];

        // Calculate remaining amount and demo fee
        // Use final_total (after promo discount) instead of raw total_tuition
        $total_remaining = $final_total - $initial_pay;
        $default_demo_fee = $total_remaining / 4;  // Split remaining amount equally into 4 demos

        // Add debug logging
        error_log(sprintf(
            "[%s] Demo Fee Calculation - User: %s\nTotal Tuition: %s\nPromo Discount: %s\nFinal Total: %s\nInitial Payment: %s\nRemaining: %s\nDemo Fee: %s",
            $current_timestamp,
            $current_user,
            number_format($total_tuition, 2),
            number_format($PR, 2),
            number_format($final_total, 2),
            number_format($initial_pay, 2),
            number_format($total_remaining, 2),
            number_format($default_demo_fee, 2)
        ));

        // Enhanced demo details tracking
        $demo_details = [];

        // Get detailed demo payments with latest information
        $demo_query = $conn->prepare("
            SELECT 
                pt.demo_type,
                SUM(pt.cash_received) as total_paid,
                pt.credit_amount as required_amount,
                pt.balance as current_balance,
                MAX(pt.created_at) as last_payment_date,
                COUNT(*) as payment_count
            FROM pos_transactions pt
            WHERE pt.student_id = ? 
            AND pt.program_id = ? 
            AND pt.payment_type = 'demo_payment'
            GROUP BY pt.demo_type
            ORDER BY pt.demo_type
        ");

        $demo_query->bind_param("ii", $student_id, $program_id);
        $demo_query->execute();
        $demo_result = $demo_query->get_result();

        // Process demo payment details
        while ($row = $demo_result->fetch_assoc()) {
            $total_paid = floatval($row['total_paid']);
            $required = floatval($row['required_amount']) ?: $default_demo_fee;
            $current_balance = floatval($row['current_balance']);

            // Enhanced status determination
            $status = 'unpaid';
            if ($total_paid >= $required - 0.01) { // Account for floating point precision
                $status = 'paid';
            } elseif ($total_paid > 0) {
                $status = 'partial';
            }

            $demo_details[$row['demo_type']] = [
                'paid_amount' => $total_paid,
                'required_amount' => $required,
                'balance' => $current_balance,
                'status' => $status,
                'last_payment' => $row['last_payment_date'],
                'payment_count' => intval($row['payment_count']),
                'timestamp' => $current_timestamp,
                'updated_by' => $current_user
            ];
        }
        $demo_query->close();

        // Fill in details for unpaid demos
        foreach (['demo1', 'demo2', 'demo3', 'demo4'] as $demo) {
            if (!isset($demo_details[$demo])) {
                $demo_details[$demo] = [
                    'paid_amount' => 0,
                    'required_amount' => $default_demo_fee,
                    'balance' => $default_demo_fee,
                    'status' => 'unpaid',
                    'last_payment' => null,
                    'payment_count' => 0,
                    'timestamp' => $current_timestamp,
                    'updated_by' => $current_user
                ];
            }
        }

        // Sum all payments for each demo (for compatibility)
        $demo_payment_sums = [];
        foreach ($demo_details as $demo_type => $details) {
            $demo_payment_sums[$demo_type] = $details['paid_amount'];
        }

        // Set paid and partially paid demos arrays (for compatibility)
        $paid_demos = [];
        $partially_paid_demos = [];
        foreach ($demo_details as $demo_type => $details) {
            if ($details['status'] === 'paid') {
                $paid_demos[] = $demo_type;
            } elseif ($details['status'] === 'partial') {
                $partially_paid_demos[$demo_type] = $details['paid_amount'];
            }
        }

        // Update remaining demos count
        $paidDemosCount = count($paid_demos);
        $remainingDemos = 4 - $paidDemosCount;

        // Get paid payment types
        $paid_payment_types = [];
        $stmt = $conn->prepare("SELECT DISTINCT payment_type FROM pos_transactions WHERE student_id = ?");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $payment_result = $stmt->get_result();

        if ($payment_result->num_rows > 0) {
            while ($payment_row = $payment_result->fetch_assoc()) {
                $payment_type = $payment_row['payment_type'];
                if ($payment_type === 'initial_payment' && !in_array('initial_payment', $paid_payment_types)) {
                    $paid_payment_types[] = 'initial_payment';
                }
                if ($payment_type === 'reservation' && !in_array('reservation', $paid_payment_types)) {
                    $paid_payment_types[] = 'reservation';
                }
                if ($payment_type === 'full_payment') {
                    $paid_payment_types = ['initial_payment', 'reservation', 'demo_payment'];
                    break;
                }
            }
        }
        $stmt->close();

        // Get schedules
        $first_transaction_stmt = $conn->prepare("
            SELECT selected_schedules 
            FROM pos_transactions 
            WHERE student_id = ? 
            AND program_id = ? 
            AND selected_schedules IS NOT NULL 
            AND selected_schedules != '' 
            AND selected_schedules != '[]'
            ORDER BY id ASC 
            LIMIT 1
        ");
        $first_transaction_stmt->bind_param("ii", $student_id, $program_id);
        $first_transaction_stmt->execute();
        $first_transaction_result = $first_transaction_stmt->get_result();

        $maintained_schedules = [];
        if ($first_transaction_result->num_rows > 0) {
            $first_transaction = $first_transaction_result->fetch_assoc();
            if (!empty($first_transaction['selected_schedules'])) {
                $schedule_data = json_decode($first_transaction['selected_schedules'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($schedule_data)) {
                    $maintained_schedules = $schedule_data;
                }
            }
        }
        $first_transaction_stmt->close();

        $selected_schedules_data = !empty($maintained_schedules) ? $maintained_schedules : [];
        if (empty($selected_schedules_data) && !empty($row_transaction['selected_schedules'])) {
            $schedule_data = json_decode($row_transaction['selected_schedules'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($schedule_data)) {
                $selected_schedules_data = $schedule_data;
            }
        }
    }

    // Get current balance
    $balance_total = 0;
    $subtotal = 0;

    if ($student_id > 0) {
        $stmt = $conn->prepare("SELECT balance, subtotal FROM pos_transactions WHERE student_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row_balance = $result->fetch_assoc()) {
            $balance_total = floatval($row_balance['balance']);
            $subtotal = floatval($row_balance['subtotal']);
        }
        $stmt->close();
    }

    $existingTransaction = json_encode([
        'payment_type' => isset($row_transaction['payment_type']) ? $row_transaction['payment_type'] : '',
        'program_id' => isset($row_transaction['program_id']) ? $row_transaction['program_id'] : '',
        'package_name' => isset($current_package['package_name']) ? $current_package['package_name'] : 'Regular Package',
        'schedule_ids' => isset($row_transaction['schedule_ids']) ? $row_transaction['schedule_ids'] : '',
        'learning_mode' => isset($row_transaction['learning_mode']) ? $row_transaction['learning_mode'] : ''
    ]);
}

?>

<!-- Add SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    @media print {
        body * {
            visibility: hidden;
        }

        #section-to-print,
        #section-to-print * {
            visibility: visible;
        }

        #section-to-print {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            box-shadow: none !important;
            border: none !important;
            margin: 0 !important;
            padding: 20px !important;
        }

        .no-print {
            display: none !important;
        }

        .print-only {
            display: block !important;
        }
    }

    .print-only {
        display: none;
    }

    .payment-hidden {
        display: none !important;
    }

    .payment-type-option {
        transition: opacity 0.3s ease;
    }

    .locked-selection {
        opacity: 0.7;
        cursor: not-allowed;
        background-color: #f3f4f6;
    }

    .locked-warning {
        animation: fadeIn 0.3s ease-in-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .payment-type-option.hidden {
        opacity: 0.3;
        pointer-events: none;
    }

    .schedule-warning {
        background: linear-gradient(135deg, #ff7b7b 0%, #ff416c 100%);
        color: white;
        padding: 10px;
        border-radius: 8px;
        margin: 10px 0;
        font-size: 14px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0.8;
        }

        100% {
            opacity: 1;
        }
    }

    .schedule-maintained {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 10px;
        border-radius: 8px;
        margin: 10px 0;
        font-size: 14px;
    }

    .schedule-locked {
        background-color: #e8f5e8 !important;
        border: 2px solid #28a745 !important;
    }

    .schedule-locked input[type="checkbox"] {
        accent-color: #28a745;
    }

    .payment-note {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 12px;
        margin-top: 5px;
    }

    .schedule-debug {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        padding: 10px;
        border-radius: 5px;
        margin: 10px 0;
        font-family: monospace;
        font-size: 12px;
    }

    .schedule-validation-error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        color: white;
        padding: 12px;
        border-radius: 8px;
        margin: 10px 0;
        font-size: 14px;
        animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {

        0%,
        100% {
            transform: translateX(0);
        }

        25% {
            transform: translateX(-5px);
        }

        75% {
            transform: translateX(5px);
        }
    }

    .payment-calculation-info {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 10px;
        border-radius: 6px;
        margin: 10px 0;
        font-size: 13px;
    }

    .demo-fee-calculated {
        background: #e8f5e8;
        border-left: 4px solid #28a745;
        padding: 8px;
        margin: 5px 0;
        font-weight: bold;
    }

    .current-balance-display {
        background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
        padding: 10px;
        border-radius: 8px;
        margin: 10px 0;
        font-weight: bold;
        text-align: center;
    }

    .program-details-section,
    .charges-section,
    .demo-fees-display {
        display: block !important;
        visibility: visible !important;
    }

    /* Schedule Edit Button Styles */
    .schedule-edit-btn {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        cursor: pointer;
        margin: 10px 0;
        transition: all 0.3s ease;
    }

    .schedule-edit-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    .schedule-edit-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .schedule-edit-content {
        background: white;
        padding: 20px;
        border-radius: 10px;
        max-width: 80%;
        max-height: 80%;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    }

    /* Enhanced Promo Selection Display Styles */
    .promo-selection-display {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        margin: 5px 0;
        font-size: 12px;
        font-weight: bold;
    }

    .promo-option-1,
    .promo-option-2 {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .promo-option-3,
    .promo-option-4 {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    }

    .custom-payment-info {
        background: #fef3c7;
        border: 1px solid #f59e0b;
        color: #92400e;
        padding: 8px;
        border-radius: 4px;
        margin: 5px 0;
        font-size: 11px;
    }

    /* Demo Excess Payment Modal Styles */
    .demo-excess-modal .swal2-popup {
        border-radius: 15px !important;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
    }

    .demo-excess-title {
        color: #1565C0 !important;
        font-weight: 600 !important;
    }

    .demo-excess-option {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }

    .demo-excess-option:hover {
        transform: translateY(-3px) !important;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
    }

    .demo-excess-option:active {
        transform: translateY(-1px) !important;
    }

    /* Loading state for submit button */
    .processing-payment {
        background: linear-gradient(45deg, #667eea, #764ba2, #667eea) !important;
        background-size: 200% 200% !important;
        animation: gradientShift 2s ease infinite !important;
    }

    @keyframes gradientShift {
        0% {
            background-position: 0% 50%;
        }

        50% {
            background-position: 100% 50%;
        }

        100% {
            background-position: 0% 50%;
        }
    }

    .locked-field {
        position: relative;
    }

    .locked-field::after {
        content: '🔒';
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 14px;
        opacity: 0.7;
        pointer-events: none;
    }

    .locked-field select:disabled {
        background-color: #f3f4f6;
        cursor: not-allowed;
        color: #6b7280;
        border-color: #d1d5db;
    }

    .locked-field select:disabled:hover {
        border-color: #d1d5db;
    }

    .locked-warning {
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Style for disabled radio buttons */
    input[type="radio"]:disabled+span {
        color: #6b7280;
        cursor: not-allowed;
    }

    /* Enhanced visual feedback for locked elements */
    select:disabled,
    input:disabled {
        opacity: 0.75;
        cursor: not-allowed !important;
    }
</style>

<div class="flex min-h-screen overflow-y-auto">
    <!-- Sidebar -->
    <div id="sidebar" class="bg-white shadow-lg w-[20%] flex-col z-10 md:flex hidden fixed min-h-screen">
        <div class="px-6 py-5 border-b border-gray-100">
            <h1 class="text-xl font-bold text-primary flex items-center">
                <i class='bx bx-plus-medical text-2xl mr-2'></i>CarePro
            </h1>
        </div>
        <?php include "includes/navbar.php" ?>
    </div>

    <!-- Main Content -->
    <div id="content" class="flex-1 flex-col w-[80%] md:ml-[20%] transition-all duration-300">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-6 py-4">
                <button id="toggleSidebar" class="md:hidden text-gray-500 hover:text-primary">
                    <i class='bx bx-menu text-2xl'></i>
                </button>
                <div class="flex items-center space-x-4">
                    <button class="text-gray-500 hover:text-primary" data-bs-toggle="modal"
                        data-bs-target="#announcementModal">
                        <i class="fa-solid fa-bullhorn mr-1"></i>New Announcement
                    </button>
                    <button class="text-gray-500 hover:text-primary" data-bs-toggle="modal"
                        data-bs-target="#viewAnnouncementsModal">
                        <i class="fa-solid fa-envelope mr-1"></i>View Announcements
                    </button>
                    <button class="text-gray-500 hover:text-primary" id="logout">
                        <i class="fa-solid fa-right-from-bracket"></i>Logout
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto bg-gray-50 p-6 min-h-screen">
            <div class="bg-white rounded-xl border-2 p-6 min-h-screen">
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold">Care Pro POS System</h1>
                    <p class="italic">Enhanced POS System with Promo Selection Options (1-4) - Updated
                        <?= date('Y-m-d H:i:s') ?>
                    </p>

                    <?php if (isset($has_balance) && $has_balance): ?>
                        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mt-2">
                            <strong><i class="fa-solid fa-triangle-exclamation mr-2"></i>Alert:</strong> This student
                            currently has an outstanding balance. Please settle the balance to re-enroll the student.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Debug Information -->
                <div id="debugInfo" class="schedule-debug" style="display: none;">
                    <strong>Debug Information:</strong><br>
                    <span id="debugMaintained">Maintained Schedules: Loading...</span><br>
                    <span id="debugSelected">Selected Schedules: Loading...</span><br>
                    <span id="debugValidation">Validation Status: Loading...</span><br>
                    <span id="debugCashDrawer">Cash Drawer: Loading...</span><br>
                    <span id="debugPromo">Promo Selection: Loading...</span>
                </div>
                <button type="button" onclick="toggleDebug()" class="bg-gray-200 px-3 py-1 rounded text-sm mb-4">
                    <i class="fa-solid fa-bug mr-1"></i>Toggle Debug Info
                </button>

                <!-- Form Section -->
                <form id="posForm">
                    <div class="flex gap-6 mb-6">
                        <div class="space-y-4 min-w-[300px]">
                            <!-- Student Name -->
                            <div class="flex items-center">
                                <span class="font-semibold w-32">Name:</span>
                                <input type="text" id="studentName" name="student_name"
                                    value="<?= $student ? htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ', ' . $student['middle_name']) : '' ?>"
                                    class="flex-1 border rounded px-3 py-1 outline-none" readonly />
                                <input type="hidden" name="student_id"
                                    value="<?= htmlspecialchars($_GET['student_id'] ?? '') ?>" />
                            </div>

                            <!-- Learning Mode -->
                            <div class="flex items-center">
                                <span class="font-semibold w-32">Learning Mode:</span>
                                <div class="flex gap-4 ml-2">
                                    <label class="flex items-center gap-1">
                                        <input type="radio" name="learning_mode" value="F2F" checked />
                                        <i class="fa-solid fa-chalkboard-teacher mr-1"></i>F2F
                                    </label>
                                    <label class="flex items-center gap-1">
                                        <input type="radio" name="learning_mode" value="Online" />
                                        <i class="fa-solid fa-laptop mr-1"></i>Online
                                    </label>
                                </div>
                            </div>

                            <!-- Program -->
                            <div class="flex items-center">
                                <span class="font-semibold w-32">Program:</span>
                                <select id="programSelect" name="program_id"
                                    class="flex-1 border rounded px-3 py-1 outline-none">
                                    <option value="">Loading programs...</option>
                                </select>
                            </div>

                            <!-- Package with Enhanced Selection Display -->
                            <div class="flex items-center">
                                <span class="font-semibold w-32">Package:</span>
                                <select id="packageSelect" name="package_id" required
                                    class="flex-1 border rounded px-3 py-1 outline-none">
                                    <option value="">Loading programs...</option>
                                </select>
                            </div>
                            <span class="text-sm italic text-yellow-500" id="locked_warning"></span>

                            <!-- Enhanced Promo Selection Information Display -->
                            <div id="promoSelectionInfo" class="hidden">
                                <div class="promo-selection-display" id="promoSelectionDisplay">
                                    <i class="fa-solid fa-tag mr-2"></i>
                                    <span id="promoSelectionText">Selection information will appear here</span>
                                </div>
                                <div class="custom-payment-info" id="customPaymentInfo" style="display: none;">
                                    <i class="fa-solid fa-info-circle mr-1"></i>
                                    <span id="customPaymentText">Custom payment information</span>
                                </div>
                            </div>
                        </div>

                        <!-- Enhanced Schedule Table with Edit Button -->
                        <div class="flex-1">
                            <div class="flex items-center justify-between mb-2">
                                <h2 class="font-semibold">Class Schedule</h2>
                                <?php if (!$open): ?>
                                    <button type="button" id="editScheduleBtn" class="schedule-edit-btn">
                                        <i class="fa-solid fa-edit mr-2"></i>Edit Schedule
                                    </button>
                                <?php endif; ?>
                            </div>

                            <div id="scheduleMaintained" class="schedule-maintained" style="display: none;">
                                <i class="fa-solid fa-lock mr-2"></i>
                                <strong>Schedule Maintained:</strong> Using the same schedule from your initial
                                enrollment.
                            </div>
                            <div id="alert" class="w-full px-4 py-2 bg-orange-200 text-semibold italic"></div>

                            <div id="scheduleWarning" class="schedule-warning" style="display: none;">
                                <i class="fa-solid fa-triangle-exclamation mr-2"></i>
                                <strong>Warning:</strong> Please select at least one schedule before processing payment!
                            </div>

                            <table id="scheduleTable" class="w-full border-collapse border text-sm">
                                <thead class="bg-gray-100">
                                    <tr>
                                        <?php foreach (['Select', 'Week Description', 'Training Date', 'Start Time', 'End Time', 'Day'] as $header): ?>
                                            <th class="border px-2 py-1"><?= htmlspecialchars($header) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>

                            <?php if ($open == false): ?>
                                <input type="hidden" id="hiddenSchedule"
                                    value="<?= htmlspecialchars(json_encode($selected_schedules_data ?? [])) ?>"
                                    name="selected_schedules" />
                                <input type="hidden" id="maintainedSchedules"
                                    value="<?= htmlspecialchars(json_encode($maintained_schedules ?? [])) ?>" />
                            <?php else: ?>
                                <input type="hidden" id="hiddenSchedule" name="selected_schedules" value="[]" />
                                <input type="hidden" id="maintainedSchedules" value="[]" />
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="button" class="bg-indigo-100 py-2 px-4 rounded mb-4" onclick="printReceiptSection2()">
                        <i class="fa-solid fa-print mr-2"></i>Print Receipt
                    </button>

                    <!-- Receipt and Payment Section -->
                    <div class="flex gap-4">
                        <!-- Receipt with proper demo display -->
                        <div class="flex-1 border-2 bg-white rounded-lg shadow-md max-w-3xl mx-auto my-4"
                            id="receiptSection">
                            <div class="bg-gray-200 p-4 rounded-t-lg text-center">
                                <h1 class="font-bold text-3xl" id="programTitle">Care Pro</h1>
                                <span class="italic text-gray-700">Official Receipt</span>
                            </div>

                            <div class="flex flex-col md:flex-row border-t">
                                <!-- Description -->
                                <div class="w-full md:w-1/2 p-4 program-details-section">
                                    <h2 class="font-semibold text-xl mb-2">Description</h2>

                                    <?php if ($open == false): ?>
                                        <ul id="descriptionList" class="space-y-1 text-sm text-gray-700">
                                            <li id="learningMode">
                                                <strong>Program:</strong>
                                                <?= htmlspecialchars($row_program['program_name'] ?? "This Data is deleted") ?>
                                                <span
                                                    class="inline-block ml-2 px-2 py-1 text-xs font-semibold rounded-full <?= ($row_transaction['learning_mode'] ?? '') === 'Online' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' ?>">
                                                    <?php if (($row_transaction['learning_mode'] ?? '') === 'Online'): ?>
                                                        <i class="fa-solid fa-laptop mr-1"></i>
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-chalkboard-teacher mr-1"></i>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars($row_transaction['learning_mode'] ?? 'N/A') ?>
                                                </span>
                                            </li>
                                            <li id="packageInfo">
                                                <strong>Package:</strong>
                                                <?= htmlspecialchars($row_promo['package_name'] ?? "Regular") ?>
                                                <?php if (isset($row_promo['selection_type']) && $row_promo['selection_type'] > 0): ?>
                                                    <span
                                                        class="promo-selection-display promo-option-<?= $row_promo['selection_type'] ?>"
                                                        style="display: inline-block; margin-left: 8px;">
                                                        Option <?= $row_promo['selection_type'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                            <li id="enrollmentDate">
                                                <strong>Enrollment Date:</strong> <?= date('Y-m-d H:i:s') ?>
                                            </li>
                                            <li id="studentInfo">
                                                <strong>Student ID:</strong> <?= htmlspecialchars($student_id) ?>
                                            </li>
                                            <?php if ($row_promo['package_name'] !== "Regular"): ?>
                                                <li id="promoInfo" class="text-green-600 font-semibold">
                                                    <?php
                                                    $selection_type = intval($row_promo['selection_type'] ?? 1);
                                                    if ($selection_type <= 2): ?>
                                                        <strong>Promo Discount:</strong> ₱<?= number_format($PR ?? 0, 2) ?>
                                                        <br><small>Automatic <?= $row_promo['percentage'] ?>% discount
                                                            applied</small>
                                                    <?php else: ?>
                                                        <strong>Custom Payment Option:</strong> <?= $row_promo['promo_type'] ?>
                                                        <br><small>Required initial:
                                                            ₱<?= number_format($row_promo['custom_initial_payment'] ?? 0, 2) ?></small>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php else: ?>
                                        <ul id="descriptionList" class="space-y-1 text-sm text-gray-700">
                                            <li id="programName">Select a program to view details</li>
                                            <li id="learningMode"></li>
                                            <li id="packageInfo">
                                                <strong>Package:</strong>
                                                <?= htmlspecialchars($current_package ? $current_package['package_name'] : "Regular Package") ?>
                                                <?php if (isset($current_package['selection_type']) && $current_package['selection_type'] > 0): ?>
                                                    <span
                                                        class="promo-selection-display promo-option-<?= $current_package['selection_type'] ?>"
                                                        style="display: inline-block; margin-left: 8px;">
                                                        Option <?= $current_package['selection_type'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                            <li id="promoInfo" class="text-green-600 font-semibold hidden"></li>
                                        </ul>
                                    <?php endif; ?>
                                </div>

                                <!-- Charges with proper demo display -->
                                <div class="w-full p-4 charges-section" id="chargesContainer">
                                    <?php if ($open == false): ?>
                                        <h1 class="font-semibold text-xl mb-2">Charges</h1>

                                        <div
                                            class="mb-3 p-2 rounded-lg <?= ($row_transaction['learning_mode'] ?? '') === 'Online' ? 'bg-blue-50 border border-blue-200' : 'bg-green-50 border border-green-200' ?>">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm font-medium">Learning Mode:</span>
                                                <span
                                                    class="px-3 py-1 text-sm font-semibold rounded-full <?= ($row_transaction['learning_mode'] ?? '') === 'Online' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800' ?>">
                                                    <?php if (($row_transaction['learning_mode'] ?? '') === 'Online'): ?>
                                                        <i class="fa-solid fa-laptop mr-1"></i>
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-chalkboard-teacher mr-1"></i>
                                                    <?php endif; ?>
                                                    <?= htmlspecialchars($row_transaction['learning_mode'] ?? 'N/A') ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="flex flex-row w-full p-4 gap-4">
                                            <ul class="flex-1 list-none space-y-1">
                                                <li>Assessment Fee:
                                                    ₱<?= number_format($row_program['assesment_fee'] ?? 0, 2) ?></li>
                                                <li>Tuition Fee: ₱<?= number_format($row_program['tuition_fee'] ?? 0, 2) ?>
                                                </li>
                                                <li>Miscellaneous Fee:
                                                    ₱<?= number_format($row_program['misc_fee'] ?? 0, 2) ?></li>
                                                <li>Uniform Fee: ₱<?= number_format($row_program['uniform_fee'] ?? 0, 2) ?>
                                                </li>
                                                <li>ID Fee: ₱<?= number_format($row_program['id_fee'] ?? 0, 2) ?></li>
                                                <li>Book Fee: ₱<?= number_format($row_program['book_fee'] ?? 0, 2) ?></li>
                                                <li>Kit Fee: ₱<?= number_format($row_program['kit_fee'] ?? 0, 2) ?></li>
                                                <?php if (($row_transaction['learning_mode'] ?? '') === 'Online' && isset($row_program['system_fee'])): ?>
                                                    <li class="text-blue-600">System Fee:
                                                        ₱<?= number_format($row_program['system_fee'] ?? 0, 2) ?></li>
                                                <?php endif; ?>
                                            </ul>

                                            <!-- Demo fees with correct calculation -->
                                            <ul class="flex-1 space-y-1 demo-fees-display">
                                                <li class="demo-fee-calculated">Demo 1 Fee: ₱<?= number_format($CDM, 2) ?>
                                                    <?php if (in_array('demo1', $paid_demos)): ?>
                                                        <span class="text-green-600 text-xs ml-1"><i
                                                                class="fa-solid fa-check-circle"></i> Paid</span>
                                                    <?php endif; ?>
                                                </li>
                                                <li class="demo-fee-calculated">Demo 2 Fee: ₱<?= number_format($CDM, 2) ?>
                                                    <?php if (in_array('demo2', $paid_demos)): ?>
                                                        <span class="text-green-600 text-xs ml-1"><i
                                                                class="fa-solid fa-check-circle"></i> Paid</span>
                                                    <?php endif; ?>
                                                </li>
                                                <li class="demo-fee-calculated">Demo 3 Fee: ₱<?= number_format($CDM, 2) ?>
                                                    <?php if (in_array('demo3', $paid_demos)): ?>
                                                        <span class="text-green-600 text-xs ml-1"><i
                                                                class="fa-solid fa-check-circle"></i> Paid</span>
                                                    <?php endif; ?>
                                                </li>
                                                <li class="demo-fee-calculated">Demo 4 Fee: ₱<?= number_format($CDM, 2) ?>
                                                    <?php if (in_array('demo4', $paid_demos)): ?>
                                                        <span class="text-green-600 text-xs ml-1"><i
                                                                class="fa-solid fa-check-circle"></i> Paid</span>
                                                    <?php endif; ?>
                                                </li>

                                                <?php if (!empty($paid_demos)): ?>
                                                    <li class="text-blue-600 text-sm mt-2">
                                                        <strong>Completed Demos:</strong><br>
                                                        <?= implode(', ', array_map('strtoupper', $paid_demos)) ?>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>

                                        <?php if ($PR > 0): ?>
                                            <div class="text-green-600 text-center mb-2 p-2 bg-green-50 rounded">
                                                <strong>Promo Discount Applied: -₱<?= number_format($PR, 2) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mt-4 p-3 bg-green-100 rounded-lg border border-green-300">
                                            <div class="flex justify-between items-center">
                                                <span class="font-bold text-lg">Total Amount:</span>
                                                <span
                                                    class="font-bold text-xl text-green-800">₱<?= number_format($final_total ?? 0, 2) ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- For first transactions, show placeholder that will be updated by JavaScript -->
                                        <div id="chargesPlaceholder">
                                            <h1 class="font-semibold text-xl mb-2">Charges</h1>
                                            <div class="text-center text-gray-500 py-8">
                                                <i class="fa-solid fa-graduation-cap text-4xl mb-4"></i>
                                                <p>Select a program to view charges and demo fees immediately</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Payment Schedule -->
                            <div class="p-4">
                                <h2 class="font-semibold text-xl mb-2">Payment Schedule</h2>
                                <div class="overflow-x-auto">
                                    <table class="w-full border border-gray-300 text-sm">
                                        <thead class="bg-gray-100 text-left">
                                            <tr>
                                                <th class="border px-2 py-1">Date</th>
                                                <th class="border px-2 py-1">Description</th>
                                                <th class="border px-2 py-1">Credit</th>
                                                <th class="border px-2 py-1">Change</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($transactions)): ?>
                                                <?php
                                                $total_credit = 0;
                                                $total_change = 0;
                                                foreach ($transactions as $row_transaction):
                                                    $credit = floatval($row_transaction['cash_received'] ?? 0);
                                                    $change = floatval($row_transaction['change_amount'] ?? 0);
                                                    $total_credit += $credit;
                                                    $total_change += $change;
                                                    ?>
                                                    <tr>
                                                        <td class="border px-2 py-1">
                                                            <?= isset($row_transaction['transaction_date']) ? date('Y-m-d', strtotime($row_transaction['transaction_date'])) : 'N/A' ?>
                                                        </td>
                                                        <td class="border px-2 py-1">
                                                            <?= htmlspecialchars($row_transaction['payment_type'] . " " . ($row_transaction['demo_type'] ?? '')); ?>
                                                        </td>
                                                        <td class="border px-2 py-1">₱<?= number_format($credit, 2); ?></td>
                                                        <td class="border px-2 py-1">₱<?= number_format($change, 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <tr class="bg-gray-100 font-semibold">
                                                    <td colspan="2" class="border px-2 py-1 text-right">Total:</td>
                                                    <td class="border px-2 py-1">₱<?= number_format($total_credit, 2); ?>
                                                    </td>
                                                    <td class="border px-2 py-1">₱<?= number_format($total_change, 2); ?>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="border px-2 py-1 text-center text-gray-500">
                                                        <div class="py-4">
                                                            <div class="text-gray-600 mb-2">
                                                                <i class="fa-solid fa-info-circle mr-2"></i>No payment
                                                                records found.
                                                            </div>
                                                            <div class="text-blue-600 text-sm">
                                                                <i class="fa-solid fa-check-circle mr-2"></i>Ready for new
                                                                enrollment
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <span class="mt-5 block">Total Remaining Balance: <span
                                            class="font-bold">₱<?= number_format($balance_total, 2) ?></span></span>
                                </div>
                            </div>

                            <div class="border-t p-4 text-center text-sm text-gray-600 italic">
                                <p>Thank you for choosing Care Pro!</p>
                                <p>For inquiries, please contact us at <strong>0912-345-6789</strong> or email
                                    <strong>support@carepro.ph</strong>.
                                </p>
                                <p class="mt-2 font-semibold text-gray-800">--- END OF RECEIPT ---</p>
                            </div>
                        </div>

                        <!-- Payment Section -->
                        <div class="w-80 border-2 p-4">
                            <div class="border-2 border-dashed p-4 mb-4">
                                <h1 class="text-xl mb-3">Type of Payment</h1>

                                <?php
                                $hiddenPayments = [];
                                if (isset($paid_payment_types)) {
                                    foreach ($paid_payment_types as $payment_type) {
                                        if ($payment_type === 'full_payment' || $payment_type === 'initial_payment' || $payment_type === 'reservation') {
                                            $hiddenPayments[] = $payment_type;
                                        }
                                    }
                                }

                                $paymentTypes = [
                                    'full_payment' => 'Full Payment',
                                    'initial_payment' => 'Initial Payment',
                                    'demo_payment' => 'Demo Payment',
                                    'reservation' => 'Reservation'
                                ];
                                ?>

                                <?php foreach ($paymentTypes as $value => $label): ?>
                                    <?php $isHidden = in_array($value, $hiddenPayments); ?>
                                    <label
                                        class="flex items-center mb-2 payment-type-option <?= $isHidden ? 'payment-hidden' : '' ?>"
                                        data-payment-type="<?= $value ?>">
                                        <input type="radio" name="type_of_payment" value="<?= $value ?>" class="mr-2"
                                            onchange="updatePaymentData()" <?= $isHidden ? 'disabled' : '' ?> />
                                        <span><?= htmlspecialchars($label) ?></span>
                                        <span class="payment-status text-sm text-gray-500 ml-2"
                                            style="display: none;"></span>
                                    </label>
                                <?php endforeach; ?>

                                <!-- Payment note for cash drawer info -->
                                <div id="paymentNote" class="payment-note" style="display: none;">
                                    <i class="fa-solid fa-info-circle mr-2"></i>
                                    <strong>Note:</strong> Cash drawer will open automatically (except for reservations)
                                </div>

                                <!-- Demo Selection with preserved selection -->
                                <div id="demoSelection" class="mt-3" style="display: none;">
                                    <label class="block text-sm font-semibold mb-2">Select Demo:</label>
                                    <select id="demoSelect" name="demo_type" class="w-full border rounded px-2 py-1">
                                        <option value="">Select Demo</option>
                                    </select>
                                </div>
                            </div>

                            <div class="border-2 border-dashed p-4 mb-4 grid grid-cols-2 gap-2">
                                <span>Total Payment</span>
                                <input type="number" id="totalPayment" name="total_payment"
                                    class="border-2 rounded px-2 py-1 outline-none" onkeyup="updatePaymentData()"
                                    step="0.01" min="0" placeholder="Amount will auto-fill" />

                                <span>Cash to pay</span>
                                <input type="number" id="cashToPay" name="cash_to_pay"
                                    class="border-2 rounded px-2 py-1 outline-none" onkeyup="updatePaymentData()"
                                    step="0.01" min="0" />

                                <span>Cash</span>
                                <input type="number" id="cash" name="cash"
                                    class="border-2 rounded px-2 py-1 outline-none" onkeyup="updatePaymentData()"
                                    step="0.01" min="0" />

                                <span>Change</span>
                                <input type="number" id="change" name="change"
                                    class="border-2 rounded px-2 py-1 outline-none" readonly step="0.01" />
                            </div>

                            <!-- Hidden fields -->
                            <input type="hidden" name="program_details" id="programDetailsHidden" />
                            <input type="hidden" name="package_details" id="packageDetailsHidden" />
                            <input type="hidden" name="subtotal" id="subtotalHidden" />
                            <input type="hidden" name="final_total" id="finalTotalHidden" />
                            <input type="hidden" name="promo_applied" id="promoAppliedHidden" value="0" />
                            <input type="" name="paid_demos" id="paidDemosField"
                                value="<?= htmlspecialchars(json_encode($paid_demos ?? [])) ?>" />
                            <input type="hidden" name="paid_payment_types" id="paidPaymentTypesField"
                                value="<?= htmlspecialchars(json_encode($paid_payment_types ?? [])) ?>" />

                            <button type="submit"
                                class="w-full px-4 py-3 bg-indigo-400 text-white rounded hover:bg-indigo-500">
                                <i class="fa-solid fa-credit-card mr-2"></i>Process Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<!-- Schedule Edit Modal -->
<div id="scheduleEditModal" class="schedule-edit-modal" style="display: none;">
    <div class="schedule-edit-content">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold"><i class="fa-solid fa-calendar-alt mr-2"></i>Edit Schedule</h2>
            <button type="button" id="closeScheduleModal" class="text-gray-500 hover:text-gray-700">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        <div id="scheduleEditContent">
            <p class="text-gray-600 mb-4">Update your schedule selection below:</p>
            <table id="editScheduleTable" class="w-full border-collapse border text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border px-2 py-1">Select</th>
                        <th class="border px-2 py-1">Week Description</th>
                        <th class="border px-2 py-1">Training Date</th>
                        <th class="border px-2 py-1">Start Time</th>
                        <th class="border px-2 py-1">End Time</th>
                        <th class="border px-2 py-1">Day</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="flex justify-end gap-3 mt-6">
            <button type="button" id="cancelScheduleEdit" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">
                <i class="fa-solid fa-times mr-2"></i>Cancel
            </button>
            <button type="button" id="saveScheduleChanges"
                class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                <i class="fa-solid fa-save mr-2"></i>Save Changes
            </button>
        </div>
    </div>
</div>

<script>
    var enrollmentLocked = <?= $enrollment_locked ? 'true' : 'false' ?>;


    function handleEnrollmentLock() {
        const $programSelect = $('#programSelect');
        const $packageSelect = $('#packageSelect');
        const $learningModeInputs = $('input[name="learning_mode"]');
        const $lockWarning = $('#locked_warning');

        if (enrollmentLocked) {
            // Lock selections
            $programSelect.prop('disabled', true).addClass('bg-gray-100');
            $packageSelect.prop('disabled', true).addClass('bg-gray-100');
            $learningModeInputs.prop('disabled', true);

            // Show warning message
            $lockWarning.html(`
            <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 my-2 rounded">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-lock text-yellow-500 mr-2"></i>
                    </div>
                    <div>
                        <p class="text-sm">
                            This student has existing enrollment records. Program, package, and learning mode selections are locked to maintain data consistency.
                            <br>
                            <small class="text-gray-600">Last updated: ${new Date().toLocaleString()}</small>
                        </p>
                    </div>
                </div>
            </div>
        `).show();

            // Add visual indication of locked state
            $programSelect.parent().addClass('locked-field');
            $packageSelect.parent().addClass('locked-field');

        } else {
            // Unlock selections
            $programSelect.prop('disabled', false).removeClass('bg-gray-100');
            $packageSelect.prop('disabled', false).removeClass('bg-gray-100');
            $learningModeInputs.prop('disabled', false);

            // Remove warning
            $lockWarning.empty().hide();

            // Remove visual indication
            $('.locked-field').removeClass('locked-field');
        }
    }

    $(document).ready(function () {

        handleEnrollmentLock();

        // Add change handlers for visual feedback
        $('#programSelect, #packageSelect').on('mouseenter', function () {
            if (enrollmentLocked) {
                $(this).attr('title', 'Selection locked - Student has existing enrollment');
            }
        });

        $('input[name="learning_mode"]').on('click', function (e) {
            if (enrollmentLocked) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Selection Locked',
                    text: 'Learning mode cannot be changed for students with existing enrollments.',
                    timer: 3000,
                    showConfirmButton: false
                });
            }
        });
        // Enhanced Global variables with promo selection support
        let selectedSchedules = [];
        let currentProgram = null;
        let currentPackage = null;
        // Existing transaction data from PHP

        let existingTransaction = <?php echo $existingTransaction ?? '{}'; ?>;

        let paidDemos = <?= json_encode($paid_demos ?? []) ?>;
        let paidPaymentTypes = <?= json_encode($paid_payment_types ?? []) ?>;
        let allPrograms = [];


        console.log(paidDemos);

        let total_ammount = <?php
        if (isset($select_program_row['total_tuition'])) {
            echo json_encode($select_program_row['total_tuition']);
        } else {
            echo '0';
        }
        ?>;

        let initial_total_pay = <?php
        if (isset($initial_pay)) {
            echo $initial_pay;
        } else {
            echo '0';
        }
        ?>;



        // Cash Drawer Variables
        let serialPort = null;
        let writer = null;
        let availablePorts = [];
        let cashDrawerConnected = false;
        let monitoringInterval = null;
        let connectionCheckInterval = 5000;

        // Maintained schedules from first transaction
        let maintainedSchedules = [];
        try {
            const maintainedData = $('#maintainedSchedules').val() || '[]';
            maintainedSchedules = JSON.parse(maintainedData);
            if (!Array.isArray(maintainedSchedules)) {
                maintainedSchedules = [];
            }
        } catch (e) {
            maintainedSchedules = [];
        }

        let existingInitialPayment = <?= $IP ?? 0 ?>;
        let existingReservation = <?= $R ?? 0 ?>;
        let currentBalance = <?= $total_tuition ?? 0 ?>;
        let isFirstTransaction = <?= $open ? 'true' : 'false' ?>;

        // =============================================================================================
        // ENHANCED: CASH DRAWER FUNCTIONS WITH RESERVATION EXCLUSION
        // =============================================================================================

        function isWebSerialSupported() {
            return 'serial' in navigator;
        }


        async function checkExcessPayment(paymentAmount, requiredAmount, paymentType) {
            const excess = paymentAmount - requiredAmount;

            if (excess > 0.01) { // Small threshold for floating point precision
                const result = await showExcessPaymentModal(paymentAmount, requiredAmount, excess, paymentType);
                return result;
            }

            return {
                processedAmount: requiredAmount,
                changeAmount: 0,
                excessOption: 'none',
                description: 'No excess payment'
            };
        }

        function showExcessPaymentModal(paymentAmount, requiredAmount, excessAmount, paymentType) {
            return new Promise((resolve) => {
                let modalContent = '';

                if (paymentType === 'initial_payment') {
                    modalContent = `
                <div style="text-align: left; padding: 20px;">
                    <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: #856404;">
                            <i class="fa-solid fa-exclamation-triangle"></i> Excess Initial Payment Detected
                        </h4>
                        <div style="font-size: 14px; line-height: 1.5;">
                            <strong>Required Initial Payment:</strong> ₱${requiredAmount.toLocaleString()}<br>
                            <strong>Amount Paid:</strong> ₱${paymentAmount.toLocaleString()}<br>
                            <strong style="color: #d63031;">Excess Amount:</strong> ₱${excessAmount.toLocaleString()}
                        </div>
                    </div>
                    
                    <p style="margin-bottom: 20px; font-weight: 500;">How would you like to handle the excess payment?</p>
                    
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <button class="excess-option-btn" data-option="treat_as_full" style="padding: 15px; border: 2px solid #28a745; background: #d4edda; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #155724; margin-bottom: 5px;">
                                1️⃣ Treat Payment as Full Initial
                            </div>
                            <small style="color: #155724;">Treat the entire payment as part of the initial tuition to reduce the balance of all demo sessions equally.</small>
                        </button>
                        
                        <button class="excess-option-btn" data-option="allocate_to_demos" style="padding: 15px; border: 2px solid #007bff; background: #d1ecf1; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #004085; margin-bottom: 5px;">
                                2️⃣ Allocate Excess to Demos (Fixed)
                            </div>
                            <small style="color: #004085;">Apply initial payment first, then allocate excess to demo sessions in order (Demo 1, Demo 2, etc.) without changing original per-demo amounts.</small>
                        </button>
                        
                        <button class="excess-option-btn" data-option="return_as_change" style="padding: 15px; border: 2px solid #ffc107; background: #fff3cd; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #856404; margin-bottom: 5px;">
                                3️⃣ Return the Excess as Change
                            </div>
                            <small style="color: #856404;">Only process the required initial amount, and return ₱${excessAmount.toLocaleString()} to the customer.</small>
                        </button>
                    </div>
                </div>
            `;
                } else if (paymentType === 'demo_payment') {
                    modalContent = `
                <div style="text-align: left; padding: 20px;">
                    <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: #856404;">
                            <i class="fa-solid fa-exclamation-triangle"></i> Excess Demo Payment Detected
                        </h4>
                        <div style="font-size: 14px; line-height: 1.5;">
                            <strong>Required Demo Payment:</strong> ₱${requiredAmount.toLocaleString()}<br>
                            <strong>Amount Paid:</strong> ₱${paymentAmount.toLocaleString()}<br>
                            <strong style="color: #d63031;">Excess Amount:</strong> ₱${excessAmount.toLocaleString()}
                        </div>
                    </div>
                    
                    <p style="margin-bottom: 20px; font-weight: 500;">How would you like to handle the excess demo payment?</p>
                    
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <button class="excess-option-btn" data-option="add_to_next_demo" style="padding: 15px; border: 2px solid #28a745; background: #d4edda; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #155724; margin-bottom: 5px;">
                                💳 Add Excess to Next Demo
                            </div>
                            <small style="color: #155724;">Carry the overpayment of ₱${excessAmount.toLocaleString()} to the next unpaid or partially paid demo session.</small>
                        </button>
                        
                        <button class="excess-option-btn" data-option="credit_to_account" style="padding: 15px; border: 2px solid #007bff; background: #d1ecf1; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #004085; margin-bottom: 5px;">
                                💰 Credit to Student's Account
                            </div>
                            <small style="color: #004085;">Save the excess of ₱${excessAmount.toLocaleString()} as a balance for future use by this student.</small>
                        </button>
                        
                        <button class="excess-option-btn" data-option="return_as_change" style="padding: 15px; border: 2px solid #ffc107; background: #fff3cd; border-radius: 8px; cursor: pointer; text-align: left; transition: all 0.3s;">
                            <div style="font-weight: bold; color: #856404; margin-bottom: 5px;">
                                💵 Return as Change
                            </div>
                            <small style="color: #856404;">Give the extra amount of ₱${excessAmount.toLocaleString()} back to the customer.</small>
                        </button>
                    </div>
                </div>
            `;
                }

                Swal.fire({
                    title: 'Handle Excess Payment',
                    html: modalContent,
                    width: '600px',
                    showCancelButton: true,
                    showConfirmButton: false,
                    cancelButtonText: 'Cancel Payment',
                    allowOutsideClick: false,
                    customClass: {
                        container: 'excess-payment-modal'
                    },
                    didOpen: () => {
                        document.querySelectorAll('.excess-option-btn').forEach(btn => {
                            btn.addEventListener('mouseenter', () => {
                                btn.style.transform = 'translateY(-2px)';
                                btn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                            });

                            btn.addEventListener('mouseleave', () => {
                                btn.style.transform = 'translateY(0)';
                                btn.style.boxShadow = 'none';
                            });

                            btn.addEventListener('click', () => {
                                const option = btn.dataset.option;
                                Swal.close();

                                const result = {
                                    choice: option,
                                    excessAmount: excessAmount,
                                    paymentAmount: paymentAmount,
                                    requiredAmount: requiredAmount,
                                    description: getExcessDescription(option, excessAmount, paymentType)
                                };

                                resolve(result);
                            });
                        });
                    }
                }).then((result) => {
                    if (result.isDismissed) {
                        resolve(null); // Cancel payment
                    }
                });
            });
        }

        function getExcessDescription(option, excessAmount, paymentType) {
            const descriptions = {
                'treat_as_full': 'Entire payment treated as initial - demo balances reduced equally',
                'allocate_to_demos': 'Excess allocated to demos in sequential order',
                'add_to_next_demo': `₱${excessAmount.toLocaleString()} credited to next demo`,
                'credit_to_account': `₱${excessAmount.toLocaleString()} credited to student account`,
                'return_as_change': `₱${excessAmount.toLocaleString()} returned as change`
            };

            return descriptions[option] || 'Excess payment processed';
        }
        async function checkForExcessPayment() {
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const totalPayment = parseFloat($('#totalPayment').val()) || 0;

            if (!paymentType || totalPayment <= 0) {
                return null;
            }

            let requiredAmount = 0;
            const demoType = $('#demoSelect').val();

            switch (paymentType) {
                case 'initial_payment':
                    if (currentPackage && currentPackage.selection_type > 2 && currentPackage.custom_initial_payment) {
                        requiredAmount = parseFloat(currentPackage.custom_initial_payment);
                    } else {
                        requiredAmount = parseFloat(currentProgram?.initial_fee || 0);
                    }
                    break;
                case 'demo_payment':
                    if (demoType) {
                        requiredAmount = calculateDemoFeeJS();
                    }
                    break;
                case 'full_payment':
                    requiredAmount = currentBalance > 0 ? currentBalance : parseFloat($('#finalTotalHidden').val()) || 0;
                    break;
                case 'reservation':
                    requiredAmount = parseFloat(currentProgram?.reservation_fee || 0);
                    break;
                default:
                    return null;
            }

            const isExcess = totalPayment > requiredAmount && requiredAmount > 0;

            if (isExcess && (paymentType === 'initial_payment' || paymentType === 'demo_payment')) {
                const excessAmount = totalPayment - requiredAmount;

                const result = await showExcessPaymentModal(totalPayment, requiredAmount, excessAmount, paymentType);
                return result;
            }

            return null;
        }

        function processExcessOption(option, paymentAmount, requiredAmount, excessAmount) {
            const result = {
                excessOption: option,
                paymentAmount: paymentAmount,
                requiredAmount: requiredAmount,
                excessAmount: excessAmount,
                processedAmount: requiredAmount,
                changeAmount: 0,
                description: ''
            };

            switch (option) {
                case 'treat_as_full':
                    result.processedAmount = paymentAmount;
                    result.description = 'Excess applied to reduce all demo balances equally';
                    break;

                case 'allocate_to_demos':
                    result.processedAmount = paymentAmount;
                    result.description = 'Excess allocated to demos in sequential order';
                    break;

                case 'add_to_next_demo':
                    result.processedAmount = paymentAmount;
                    result.description = 'Excess credited to next demo payment';
                    break;

                case 'credit_to_account':
                    result.processedAmount = paymentAmount;
                    result.description = 'Excess credited to student account';
                    break;

                case 'return_as_change':
                    result.processedAmount = requiredAmount;
                    result.changeAmount = excessAmount;
                    result.description = 'Excess returned as change';
                    break;
            }

            // Update change field if returning as change
            if (result.changeAmount > 0) {
                const currentCash = parseFloat($('#cash').val()) || 0;
                const newChange = currentCash - result.processedAmount;
                $('#change').val(Math.max(0, newChange + result.changeAmount).toFixed(2));
            }

            return result;
        }

        /**
         * Add excess payment info to receipt
         */
        function addExcessInfoToReceipt(excessData) {
            if (!excessData || !excessData.choice) return;

            // Remove existing excess info
            $('#excessPaymentInfo').remove();

            const excessInfoHtml = `
        <div id="excessPaymentInfo" style="background: #e8f5e8; border: 1px solid #28a745; border-radius: 8px; padding: 15px; margin: 15px 0; font-size: 13px;">
            <div style="font-weight: bold; color: #155724; margin-bottom: 8px;">
                <i class="fa-solid fa-info-circle"></i> Excess Payment Handling
            </div>
            <div style="margin-bottom: 5px;">
                <strong>Payment:</strong> ₱${excessData.paymentAmount.toLocaleString()} | 
                <strong>Required:</strong> ₱${excessData.requiredAmount.toLocaleString()} | 
                <strong>Excess:</strong> ₱${excessData.excessAmount.toLocaleString()}
            </div>
            <div style="margin-bottom: 8px;">
                <strong>Action:</strong> ${excessData.description}
            </div>
            <div style="font-size: 11px; color: #6c757d;">
                Processed: ${new Date().toLocaleString()} by Scraper001
            </div>
        </div>
    `;

            // Insert before payment schedule
            $('.p-4:last').before(excessInfoHtml);
        }

        async function autoSearchCashDrawer() {
            if (!isWebSerialSupported()) {
                updateCashDrawerStatus(false, "Browser not supported");
                return false;
            }

            try {
                const ports = await navigator.serial.getPorts();
                availablePorts = ports;

                if (ports.length === 0) {
                    updateCashDrawerStatus(false, "No ports found");
                    return false;
                }

                for (let port of ports) {
                    if (await connectToCashDrawer(port)) {
                        startPortMonitoring();
                        return true;
                    }
                }

                updateCashDrawerStatus(false, "Connection failed");
                return false;

            } catch (error) {
                updateCashDrawerStatus(false, "Search error");
                return false;
            }
        }

        async function requestNewCashDrawerPort() {
            if (!isWebSerialSupported()) {
                showCashDrawerAlert("Web Serial API not supported. Please use Chrome/Edge browser.");
                return false;
            }

            try {
                const newPort = await navigator.serial.requestPort();
                availablePorts = [newPort];

                if (await connectToCashDrawer(newPort)) {
                    startPortMonitoring();
                    return true;
                }
                return false;

            } catch (error) {
                if (error.name !== 'NotFoundError') {
                }
                return false;
            }
        }

        async function connectToCashDrawer(port) {
            try {
                await port.open({
                    baudRate: 9600,
                    dataBits: 8,
                    stopBits: 1,
                    parity: 'none',
                    flowControl: 'none'
                });

                serialPort = port;
                writer = port.writable.getWriter();
                cashDrawerConnected = true;

                updateCashDrawerStatus(true, "Connected");
                showSuccessNotification("Cash Drawer Connected", "Ready for automatic opening on payments (except reservations)");

                return true;

            } catch (error) {
                updateCashDrawerStatus(false, "Connection failed");
                return false;
            }
        }

        // Enhanced: Cash drawer opening with reservation exclusion and promo selection awareness
        async function openCashDrawerOnPayment(paymentAmount, paymentType) {
            // Enhanced: Skip cash drawer for reservations and initial payments
            if (paymentType === 'reservation' || paymentType === 'initial_payment') {

                return true; // Return success without opening drawer
            }

            if (!cashDrawerConnected || !writer) {

                return false;
            }

            try {
                const command = new Uint8Array([27, 112, 0, 25, 25]);
                await writer.write(command);
                showCashDrawerOpenSuccess(paymentAmount, paymentType);

                return true;

            } catch (error) {
                showCashDrawerAlert("Failed to open cash drawer! Please check connection.");
                setTimeout(() => {
                    autoSearchCashDrawer();
                }, 1000);
                return false;
            }
        }

        function startPortMonitoring() {
            if (monitoringInterval) {
                clearInterval(monitoringInterval);
            }

            monitoringInterval = setInterval(async () => {
                if (cashDrawerConnected && serialPort) {
                    try {
                        if (!serialPort.readable || !serialPort.writable) {
                            throw new Error("Port no longer accessible");
                        }
                    } catch (error) {
                        handleCashDrawerDisconnection();
                    }
                }
            }, connectionCheckInterval);
        }

        function handleCashDrawerDisconnection() {
            cashDrawerConnected = false;
            writer = null;
            serialPort = null;

            updateCashDrawerStatus(false, "DISCONNECTED");

            Swal.fire({
                icon: 'warning',
                title: 'Cash Drawer Disconnected!',
                text: 'The cash drawer has been disconnected. Please reconnect it.',
                confirmButtonText: 'Reconnect',
                showCancelButton: true,
                cancelButtonText: 'Ignore',
                timer: 10000
            }).then((result) => {
                if (result.isConfirmed) {
                    requestNewCashDrawerPort();
                }
            });

            if (monitoringInterval) {
                clearInterval(monitoringInterval);
                monitoringInterval = null;
            }
        }

        function updateCashDrawerStatus(connected, statusMessage = "") {
            const status = connected ? 'Connected' : 'Disconnected';
            const fullStatus = statusMessage ? `${status} (${statusMessage})` : status;

            let statusIndicator = $('#cashDrawerStatus');

            if (statusIndicator.length === 0) {
                $('body').append(`
                <div id="cashDrawerStatus" style="
                    position: fixed;
                    top: 10px;
                    right: 10px;
                    padding: 10px 15px;
                    border-radius: 8px;
                    font-size: 11px;
                    font-weight: bold;
                    z-index: 9999;
                    min-width: 220px;
                    text-align: center;
                    box-shadow: 0 3px 10px rgba(0,0,0,0.2);
                    cursor: pointer;
                    transition: all 0.3s ease;
                    ${connected
                        ? 'background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;'
                        : 'background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; animation: pulse 2s infinite;'}
                ">
                    <i class="fa-solid fa-cash-register"></i> Cash Drawer: ${fullStatus}<br>
                    <small style="opacity: 0.9;">${new Date().toLocaleString()}</small>
                    ${connected ? '' : '<br><small><i class="fa-solid fa-mouse-pointer"></i> Click to reconnect</small>'}
                </div>
            `);

                $('#cashDrawerStatus').click(() => {
                    if (!connected) {
                        requestNewCashDrawerPort();
                    }
                });

            } else {
                statusIndicator
                    .html(`<i class="fa-solid fa-cash-register"></i> Cash Drawer: ${fullStatus}<br><small style="opacity: 0.9;">${new Date().toLocaleString()}</small>${connected ? '' : '<br><small><i class="fa-solid fa-mouse-pointer"></i> Click to reconnect</small>'}`)
                    .css({
                        'background': connected
                            ? 'linear-gradient(135deg, #10b981 0%, #059669 100%)'
                            : 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
                        'color': 'white',
                        'animation': connected ? 'none' : 'pulse 2s infinite'
                    });
            }
        }

        function showCashDrawerAlert(message) {
            Swal.fire({
                icon: 'error',
                title: 'Cash Drawer Alert',
                text: message,
                confirmButtonText: 'OK',
                timer: 5000
            });
        }

        function showSuccessNotification(title, message) {
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        }

        function showCashDrawerOpenSuccess(amount, paymentType) {
            $('.cash-drawer-success').remove();

            const successDiv = $(`
            <div class="cash-drawer-success" style="
                position: fixed;
                top: 70px;
                right: 10px;
                padding: 15px 20px;
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 10px;
            font-size: 13px;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
            animation: slideInRight 0.4s ease-out;
            min-width: 250px;
            ">
                <div style="text-align: center;">
                    <div style="font-size: 20px; margin-bottom: 5px;"><i class="fa-solid fa-cash-register"></i></div>
                    <div style="font-size: 14px;">Cash Drawer Opened!</div>
                    <div style="font-size: 11px; opacity: 0.9; margin-top: 3px;">
                        ${paymentType.toUpperCase()}: ₱${amount.toLocaleString()}
                    </div>
                    <div style="font-size: 10px; opacity: 0.8; margin-top: 2px;">
                        ${new Date().toLocaleString()}
                    </div>
                </div>
            </div>
                `);

            $('body').append(successDiv);

            setTimeout(() => {
                successDiv.fadeOut(500, function () {
                    $(this).remove();
                });
            }, 4000);
        }

        // =============================================================================================
        // ENHANCED: DEMO CALCULATION FUNCTIONS WITH PROMO SELECTION SUPPORT
        // =============================================================================================
        function calculateDemoFeeJS() {
            if (isFirstTransaction && currentProgram) {
                // For first transactions, calculate based on program total and initial payment
                const totalTuition = parseFloat($('#finalTotalHidden').val()) || parseFloat(currentProgram.total_tuition || 0);
                const initialPayment = initial_total_pay || parseFloat(currentProgram.initial_fee || 0);

                // Get promo discount directly from PHP variable


                // Calculate new tuition after promo discount
                const newTuition = totalTuition;

                // Calculate remaining amount after initial payment 
                const remainingAmount = newTuition - initialPayment;

                // Split remaining amount into 4 equal demo payments
                return remainingAmount / 4;
            } else {
                // For existing transactions, use current balance
                const promo = <?php echo isset($row_promo['enrollment_fee']) ? $row_promo['enrollment_fee'] : 0 ?>;
                // Use current balance directly without subtracting promo again
                const currentBalanceAmount = currentBalance || 0;

                const paidDemosCount = paidDemos.length;
                const remainingDemos = 4 - paidDemosCount;

                if (remainingDemos > 0 && currentBalanceAmount > 0) {
                    // Calculate remaining demo fee based on current balance
                    return currentBalanceAmount / remainingDemos;
                }
            }
            return 0;
        }
        // Enhanced: Update demo fees and make them visible immediately with promo awareness
        function updateDemoFeesDisplay() {
            if (!currentProgram) return;

            const demoFee = calculateDemoFeeJS();
            const demoDetails = <?= json_encode($demo_details ?? []) ?>;

            const demoFeesHtml = [1, 2, 3, 4].map(i => {
                const demoName = `demo${i}`;
                const demoInfo = demoDetails[demoName] || {};
                const isPaid = paidDemos.includes(demoName);

                // Get actual payment details
                const paidAmount = demoInfo.paid_amount || 0;
                const requiredAmount = demoFee;
                const remainingBalance = Math.max(0, requiredAmount - paidAmount);
                const status = demoInfo.status || 'unpaid';

                // Generate status HTML inline instead of using separate function
                let statusHtml = '';
                if (status === 'paid') {
                    statusHtml = '<span class="text-green-600 text-xs ml-1"><i class="fa-solid fa-check-circle"></i> Paid</span>';
                } else if (status === 'partial') {
                    statusHtml = `<span class="text-orange-600 text-xs ml-1">
                <i class="fa-solid fa-clock"></i> Partially Paid (₱${paidAmount.toLocaleString()})</span>`;
                }

                return `<li class="demo-fee-calculated">
            Demo ${i} Fee: ₱${requiredAmount.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })}
            ${statusHtml}
            ${status === 'partial' ?
                        `<br><small class="text-gray-600">Remaining: ₱${remainingBalance.toLocaleString()}</small>`
                        : ''}
        </li>`;
            }).join('');

            if ($('.demo-fees-display').length > 0) {
                $('.demo-fees-display').html(demoFeesHtml).show();
            } else if ($('#demoFeesDisplay').length > 0) {
                $('#demoFeesDisplay').html(demoFeesHtml).show();
            }

            // Update payment amount if demo payment is selected
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const selectedDemo = $('#demoSelect').val();
            if (paymentType === 'demo_payment' && selectedDemo) {
                const selectedDemoInfo = demoDetails[selectedDemo] || {};
                const demoBalance = selectedDemoInfo.status === 'partial' ?
                    selectedDemoInfo.balance : demoFee;
                $('#totalPayment').val(demoBalance.toFixed(2));
                $('#cashToPay').val(demoBalance.toFixed(2));
            }
        }
        // =============================================================================================
        // ENHANCED: PROGRAM DISPLAY FUNCTIONS WITH PROMO SELECTION INTEGRATION
        // =============================================================================================

        // Enhanced: Show program details immediately when selected with promo selection awareness
        function showProgramDetailsImmediately(program) {
            const selectedLearningMode = $('input[name="learning_mode"]:checked').val() || 'F2F';
            const learningModeIcon = selectedLearningMode === 'Online' ? '<i class="fa-solid fa-laptop"></i>' : '<i class="fa-solid fa-chalkboard-teacher"></i>';

            // Enhanced: Show program title immediately
            $('#programTitle').text(`Care Pro - ${program.program_name}`).show();

            // Enhanced: Show program name with learning mode
            $('#programName').html(`
                <strong>Program:</strong> ${program.program_name}
            <span class="inline-block ml-2 px-2 py-1 text-xs font-semibold rounded-full ${selectedLearningMode === 'Online' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}">
                ${learningModeIcon} ${selectedLearningMode}
            </span>
            `).show();

            // Enhanced: Show enrollment date and student info
            $('#enrollmentDate').html(`<strong>Enrollment Date:</strong> ${new Date().toLocaleString()}`).show();
            $('#studentInfo').show();

            // Enhanced: Create and show charges section immediately
            const chargesHtml = `
                <h1 class="font-semibold text-xl mb-2">Charges</h1>
        <div class="mb-3 p-2 rounded-lg ${selectedLearningMode === 'Online' ? 'bg-blue-50 border border-blue-200' : 'bg-green-50 border border-green-200'}">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium">Learning Mode:</span>
                <span class="px-3 py-1 text-sm font-semibold rounded-full ${selectedLearningMode === 'Online' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'}">
                    ${learningModeIcon} ${selectedLearningMode}
                </span>
            </div>
        </div>
        <div class="flex flex-row w-full p-4 gap-4">
            <ul class="flex-1 list-none space-y-1">
                <li>Assessment Fee: ₱${parseFloat(program.assesment_fee || 0).toLocaleString()}</li>
                <li>Tuition Fee: ₱${parseFloat(program.tuition_fee || 0).toLocaleString()}</li>
                <li>Miscellaneous Fee: ₱${parseFloat(program.misc_fee || 0).toLocaleString()}</li>
                <li>Uniform Fee: ₱${parseFloat(program.uniform_fee || 0).toLocaleString()}</li>
                <li>ID Fee: ₱${parseFloat(program.id_fee || 0).toLocaleString()}</li>
                <li>Book Fee: ₱${parseFloat(program.book_fee || 0).toLocaleString()}</li>
                <li>Kit Fee: ₱${parseFloat(program.kit_fee || 0).toLocaleString()}</li>
                ${selectedLearningMode === 'Online' && program.system_fee ?
                    `<li class="text-blue-600">System Fee: ₱${parseFloat(program.system_fee).toLocaleString()}</li>` : ''}
            </ul>
            <ul class="flex-1 space-y-1 demo-fees-display" id="demoFeesDisplay">
                <!-- Demo fees will be calculated and shown here -->
            </ul>
        </div>
        <div class="mt-4 p-3 bg-green-100 rounded-lg border border-green-300">
            <div class="flex justify-between items-center">
                <span class="font-bold text-lg">Total Amount:</span>
                <span class="font-bold text-xl text-green-800" id="totalAmountDisplay">₱${parseFloat(program.total_tuition || 0).toLocaleString()}</span>
            </div>
        </div>
            `;

            // Enhanced: Replace placeholder or update existing charges
            if ($('#chargesPlaceholder').length > 0) {
                $('#chargesPlaceholder').html(chargesHtml);
            } else {
                $('#chargesContainer').html(chargesHtml);
            }

            // Force show the charges section
            $('#chargesContainer, .charges-section').show();

            // Enhanced: Calculate and show demo fees immediately
            updateDemoFeesDisplay();

            // Enhanced: Calculate and show totals
            calculateTotal(program, currentPackage);


        }

        // =============================================================================================
        // ENHANCED: PROMO SELECTION DISPLAY FUNCTIONS
        // =============================================================================================

        // Enhanced: Display promo selection information in POS
        function displayPromoSelectionInfo(packageData) {
            const selectionType = parseInt(packageData.selection_type || 1);
            const promoInfoDiv = $('#promoSelectionInfo');
            const promoDisplayDiv = $('#promoSelectionDisplay');
            const customPaymentDiv = $('#customPaymentInfo');
            const customPaymentText = $('#customPaymentText');

            // Show the promo selection info container
            promoInfoDiv.removeClass('hidden').show();

            // Update selection display with appropriate styling
            promoDisplayDiv.removeClass('promo-option-1 promo-option-2 promo-option-3 promo-option-4')
                .addClass(`promo-option-${selectionType}`);

            let selectionText = `Option ${selectionType} - `;
            let customPaymentInfo = '';

            if (selectionType <= 2) {
                // Options 1-2: Percentage-based
                selectionText += `Auto ${packageData.percentage}% Discount`;
                customPaymentDiv.hide();
            } else {
                // Options 3-4: Custom payment
                selectionText += 'Manual Payment Declaration';
                customPaymentInfo = `Required Initial Payment: ₱${parseFloat(packageData.custom_initial_payment || 0).toLocaleString()}`;
                customPaymentText.text(customPaymentInfo);
                customPaymentDiv.show();
            }

            $('#promoSelectionText').text(selectionText);

            // Update debug info
            updateDebugInfo();


        }

        // Enhanced: Hide promo selection info for regular packages
        function hidePromoSelectionInfo() {
            $('#promoSelectionInfo').addClass('hidden').hide();

        }

        // =============================================================================================
        // ENHANCED: SCHEDULE EDIT FUNCTIONALITY WITH PROMO AWARENESS
        // =============================================================================================

        $('#editScheduleBtn').click(function () {
            if (!currentProgram) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No Program Selected',
                    text: 'Please select a program first to edit schedules.',
                    confirmButtonText: 'OK'
                });
                return;
            }


            // Load available schedules for editing
            $.ajax({
                url: 'functions/ajax/get_schedules.php',
                type: 'GET',
                data: {
                    program_id: currentProgram.id,
                    timestamp: '2025-06-21 03:25:31',
                    user: 'Scraper001'
                },
                dataType: 'json',
                success: function (schedules) {

                    if (schedules && schedules.length > 0) {
                        populateEditScheduleTable(schedules);
                        $('#scheduleEditModal').show();
                    } else {
                        Swal.fire({
                            icon: 'info',
                            title: 'No Schedules Available',
                            text: 'No schedules found for this program.',
                            confirmButtonText: 'OK'
                        });
                    }
                },
                error: function (xhr, status, error) {
                    console.error('❌ Schedule loading error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error Loading Schedules',
                        text: 'Failed to load schedules for editing. Please try again.',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });

        function populateEditScheduleTable(schedules) {
            const tbody = $('#editScheduleTable tbody').empty();

            if (!schedules || schedules.length === 0) {
                tbody.append('<tr><td colspan="6" class="border px-2 py-1 text-center text-gray-500">No schedules available for this program</td></tr>');
                return;
            }

            // Get currently selected schedules from hidden field
            let currentlySelected = [];
            try {
                const hiddenScheduleValue = $('#hiddenSchedule').val() || '[]';
                currentlySelected = JSON.parse(hiddenScheduleValue);
                if (!Array.isArray(currentlySelected)) {
                    currentlySelected = [];
                }
            } catch (e) {
                console.error('⚠️ Error parsing current schedules:', e);
                currentlySelected = [];
            }


            schedules.forEach(schedule => {
                // Check if this schedule is currently selected
                const isSelected = currentlySelected.some(cs => {
                    return (cs.id == schedule.id || cs.schedule_id == schedule.id);
                });



                const row = `
        <tr data-schedule-id="${schedule.id}" class="${isSelected ? 'bg-blue-50' : ''}">
                <td class="border px-2 py-1">
                    <input type="checkbox" class="edit-schedule-checkbox" 
                           data-schedule-id="${schedule.id}"
                           ${isSelected ? 'checked' : ''} />
                    ${isSelected ? '<i class="fa-solid fa-check text-green-600 ml-1" title="Currently Selected"></i>' : ''}
                </td>
                <td class="border px-2 py-1">${schedule.week_description || ''}</td>
                <td class="border px-2 py-1">${schedule.training_date || ''}</td>
                <td class="border px-2 py-1">${schedule.start_time || ''}</td>
                <td class="border px-2 py-1">${schedule.end_time || ''}</td>
                <td class="border px-2 py-1">${schedule.day_of_week || ''}</td>
            </tr>
        `;

                tbody.append(row);
            });


        }

        $('#closeScheduleModal, #cancelScheduleEdit').click(function () {
            $('#scheduleEditModal').hide();
        });

        $('#saveScheduleChanges').click(function () {
            const newSelectedSchedules = [];
            let selectedCount = 0;




            // Collect all checked schedules with proper database structure
            $('#editScheduleTable .edit-schedule-checkbox:checked').each(function () {
                const checkbox = $(this);
                const row = checkbox.closest('tr');
                const scheduleId = checkbox.data('schedule-id');

                // Create schedule data matching database structure
                const scheduleData = {
                    id: scheduleId.toString(),
                    schedule_id: scheduleId.toString(),
                    week_description: row.find('td:eq(1)').text().trim(),
                    weekDescription: row.find('td:eq(1)').text().trim(),
                    training_date: row.find('td:eq(2)').text().trim(),
                    trainingDate: row.find('td:eq(2)').text().trim(),
                    start_time: row.find('td:eq(3)').text().trim(),
                    startTime: row.find('td:eq(3)').text().trim(),
                    end_time: row.find('td:eq(4)').text().trim(),
                    endTime: row.find('td:eq(4)').text().trim(),
                    day_of_week: row.find('td:eq(5)').text().trim(),
                    dayOfWeek: row.find('td:eq(5)').text().trim()
                };

                newSelectedSchedules.push(scheduleData);
                selectedCount++;


            });

            // Update the global selectedSchedules array
            selectedSchedules = newSelectedSchedules;

            // Update the hidden field with proper JSON structure
            const scheduleJson = JSON.stringify(selectedSchedules);
            $('#hiddenSchedule').val(scheduleJson);



            // Update the database enrollment record
            const studentId = $('input[name="student_id"]').val();
            const programId = $('#programSelect').val();

            if (studentId && programId) {
                $.ajax({
                    url: 'functions/ajax/update_enrollment_schedule.php',
                    type: 'POST',
                    data: {
                        student_id: studentId,
                        program_id: programId,
                        selected_schedules: scheduleJson,
                        timestamp: '2025-06-21 03:25:31',
                        updated_by: 'Scraper001'
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {

                        } else {

                        }
                    },
                    error: function (xhr, status, error) {

                    }
                });
            }

            // Refresh the main schedule table to show updated selection
            if (currentProgram && currentProgram.id) {
                $.ajax({
                    url: 'functions/ajax/get_schedules.php',
                    type: 'GET',
                    data: {
                        program_id: currentProgram.id,
                        timestamp: '2025-06-21 03:25:31'
                    },
                    dataType: 'json',
                    success: function (schedules) {
                        populateSchedule(schedules);

                    },
                    error: function () {

                    }
                });
            }

            // Close the modal
            $('#scheduleEditModal').hide();

            // Show success message with timestamp
            Swal.fire({
                icon: 'success',
                title: 'Schedule Updated Successfully!',
                html: `
        <div style="text-align: left;">
                <p><strong>Selected Schedules:</strong> ${selectedCount}</p>
                <p><strong>Updated:</strong> 2025-06-21 03:25:31</p>
                <p><strong>By:</strong> Scraper001</p>
            </div>
        `,
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });

            // Update debug info if visible
            if ($('#debugInfo').is(':visible')) {
                updateDebugInfo();
            }

            // Validate schedule selection for current payment type
            validateScheduleSelection();
        });

        // =============================================================================================
        // ENHANCED: POS FUNCTIONALITY WITH PROMO SELECTION SUPPORT
        // =============================================================================================

        window.toggleDebug = function () {
            const debugDiv = $('#debugInfo');
            debugDiv.toggle();
            updateDebugInfo();
        };

        const updateDebugInfo = () => {
            const scheduleJson = $('#hiddenSchedule').val() || '[]';
            let selectedCount = 0;
            let scheduleParseStatus = 'Valid';

            try {
                const parsed = JSON.parse(scheduleJson);
                selectedCount = Array.isArray(parsed) ? parsed.length : 0;
            } catch (e) {
                selectedCount = 'Parse Error';
                scheduleParseStatus = 'Invalid JSON';
            }

            const domCheckedCount = document.querySelectorAll('.row-checkbox:checked').length;
            const validationStatus = validateScheduleSelection() ? 'PASS' : 'FAIL';
            const paymentType = $('input[name="type_of_payment"]:checked').val() || 'None';

            $('#debugMaintained').text(`Maintained Schedules: ${maintainedSchedules.length} items`);
            $('#debugSelected').text(`Selected Schedules: ${selectedCount} items (${scheduleParseStatus})`);
            $('#debugValidation').text(`Validation Status: ${validationStatus} (Payment: ${paymentType}, DOM: ${domCheckedCount})`);
            $('#debugCashDrawer').text(`Cash Drawer: ${cashDrawerConnected ? 'Connected' : 'Disconnected'}`);

            if (currentPackage) {
                $('#debugPromo').text(`Promo Selection: Option ${currentPackage.selection_type} - ${currentPackage.package_name}`);
            } else {
                $('#debugPromo').text(`Promo Selection: No Promo`);
            }


        };

        const ajax = (url, data = {}, success, error = 'Request failed') => {
            $.ajax({
                url, type: 'GET', data, dataType: 'json', timeout: 10000,
                success,
                error: () => {
                    alert(error);
                }
            });
        };

        const populateSelect = (selector, data, valueKey, textKey, placeholder = 'Select option', regularPackage = null, descriptionKey = null) => {
            const $select = $(selector).empty().append(`<option value="">${placeholder}</option>`);

            if (regularPackage) {
                $select.append(`<option value="${regularPackage}">${regularPackage}</option>`);
            }

            if (data?.length) {
                data.forEach(item => {
                    let optionText = item[textKey];
                    if (descriptionKey && item[descriptionKey]) {
                        const description = item[descriptionKey].toLowerCase();
                        if (description.includes('online')) {
                            optionText += ' (Online Learning)';
                        } else if (description.includes('f2f') || description.includes('face to face') || description.includes('F2F') || description.includes('classroom')) {
                            optionText += ' (F2F)';
                        } else if (description.includes('hybrid') || description.includes('blended')) {
                            optionText += ' (Hybrid Learning)';
                        } else {
                            optionText += ` (${item[descriptionKey]})`;
                        }
                    }
                    $select.append(`<option value="${item[valueKey]}">${optionText}</option>`);
                });
                $select.prop('disabled', false);
            } else {
                $select.prop('disabled', true);
            }
        };

        function filterProgramsByLearningMode(selectedMode) {
            if (!allPrograms.length) {
                loadAllPrograms(() => {
                    filterProgramsByLearningMode(selectedMode);
                });
                return;
            }

            let modeFilter;
            switch (selectedMode) {
                case 'F2F':
                    modeFilter = 'F2F';
                    break;
                case 'Online':
                    modeFilter = 'Online';
                    break;
                default:
                    populateSelect('#programSelect', allPrograms, 'id', 'program_name', 'Select a program', null, 'learning_mode');
                    return;
            }

            const filteredPrograms = allPrograms.filter(program => {
                return program.learning_mode === modeFilter;
            });

            populateSelect('#programSelect', filteredPrograms, 'id', 'program_name',
                `Select a ${selectedMode} program`, null, 'learning_mode');

            resetProgram();
            resetSchedule();

            if (existingTransaction.program_id) {
                const existingProgram = filteredPrograms.find(p => p.id === existingTransaction.program_id);
                if (existingProgram) {
                    $('#programSelect').val(existingTransaction.program_id).trigger('change');
                }
            }
        }

        // Enhanced: Preserve demo selection completely
        const populateDemoSelect = (preserveSelection = true) => {
            const $demoSelect = $('#demoSelect');
            const currentSelection = preserveSelection ? $demoSelect.val() : '';
            const currentTimestamp = '2025-06-29 07:40:14';
            const currentUser = 'scrapper22';

            $demoSelect.empty().append('<option value="">Select Demo</option>');

            const allDemos = [
                { value: 'demo1', label: '1st Practical Demo' },
                { value: 'demo2', label: '2nd Practical Demo' },
                { value: 'demo3', label: '3rd Practical Demo' },
                { value: 'demo4', label: '4th Practical Demo' }
            ];

            // Get demo details from PHP with actual paid amounts
            const demoDetails = <?= json_encode($demo_details ?? []) ?>;
            const originalDemoFee = calculateDemoFeeJS(); // Get the original demo fee

            // Filter available demos considering both paid and partially paid status
            const availableDemos = allDemos.filter(demo => {
                const demoInfo = demoDetails[demo.value] || {};
                return demoInfo.status !== 'paid'; // Keep demos that aren't fully paid
            });

            // Add demos to select with remaining balance for partial payments
            availableDemos.forEach(demo => {
                const demoInfo = demoDetails[demo.value] || {};
                let label = demo.label;

                if (demoInfo.status === 'partial') {
                    // Calculate remaining balance by subtracting paid amount from original fee
                    const paidAmount = demoInfo.paid_amount || 0;
                    const remainingBalance = originalDemoFee - paidAmount;

                    // Show original fee and remaining balance
                    label = `${demo.label} (₱${originalDemoFee.toLocaleString()} - Remaining: ₱${remainingBalance.toLocaleString()})`;

                    $demoSelect.append(`<option value="${demo.value}" 
                data-remaining="${remainingBalance}"
                data-status="${demoInfo.status}"
                data-paid="${paidAmount}"
                data-original="${originalDemoFee}"
            >${label}</option>`);
                } else {
                    // For unpaid demos, show original fee
                    label = `${demo.label} (₱${originalDemoFee.toLocaleString()})`;

                    $demoSelect.append(`<option value="${demo.value}" 
                data-remaining="${originalDemoFee}"
                data-status="unpaid"
                data-paid="0"
                data-original="${originalDemoFee}"
            >${label}</option>`);
                }
            });

            // Remove any existing change event handlers
            $demoSelect.off('change');

            // Add new change event handler
            $demoSelect.on('change', function () {
                const selectedDemo = $(this).val();
                if (selectedDemo) {
                    const selectedOption = $(this).find(`option[value="${selectedDemo}"]`);
                    const status = selectedOption.data('status');
                    const paidAmount = parseFloat(selectedOption.data('paid'));
                    const originalFee = parseFloat(selectedOption.data('original'));
                    const remainingBalance = originalFee - paidAmount;

                    // Set the payment amount based on remaining balance
                    $('#totalPayment').val(remainingBalance.toFixed(2));
                    $('#cashToPay').val(remainingBalance.toFixed(2));

                    console.log(`[${currentTimestamp}] ${currentUser}: Demo ${selectedDemo} selected
                Status: ${status}
                Original Fee: ₱${originalFee.toFixed(2)}
                Paid Amount: ₱${paidAmount.toFixed(2)}
                Remaining: ₱${remainingBalance.toFixed(2)}`);

                    // Clear cash and change fields
                    $('#cash').val('');
                    $('#change').val('0.00');

                    // Trigger payment calculation update
                    updatePaymentAmountsEnhanced();
                }
            });

            // Rest of the code remains the same...
            if (currentSelection && availableDemos.some(demo => demo.value === currentSelection)) {
                setTimeout(() => {
                    $demoSelect.val(currentSelection).trigger('change');
                }, 50);
            }

            const $demoPaymentOption = $('label[data-payment-type="demo_payment"]');
            const $demoPaymentInput = $('input[name="type_of_payment"][value="demo_payment"]');
            const $demoPaymentStatus = $demoPaymentOption.find('.payment-status');

            if (availableDemos.length === 0) {
                $demoPaymentOption.addClass('payment-hidden');
                $demoPaymentInput.prop('disabled', true);
                $demoPaymentStatus.text('(All demos completed)').show();
            } else {
                $demoPaymentOption.removeClass('payment-hidden');
                $demoPaymentInput.prop('disabled', false);
                let remainingCount = availableDemos.filter(d => demoDetails[d.value]?.status !== 'partial').length;
                let partialCount = availableDemos.filter(d => demoDetails[d.value]?.status === 'partial').length;

                let statusText = `(${remainingCount} remaining`;
                if (partialCount > 0) {
                    statusText += `, ${partialCount} partial`;
                }
                statusText += ')';

                $demoPaymentStatus.text(statusText).show();
            }

            if ($('#debugInfo').is(':visible')) {
                updateDebugInfo();
            }

            return availableDemos.length > 0;
        };

        // Enhanced: Calculate total with promo selection support
        const calculateTotal = (program, package) => {
            let subtotal = parseFloat(program.total_tuition || 0);
            let discount = 0;

            const learningMode = $('input[name="learning_mode"]:checked').val();
            if (learningMode === 'Online' && program.learning_mode !== 'Online') {
                subtotal += parseFloat(program.system_fee || 0);
            }

            // Fixed package calculation
            if (package && package.package_name !== 'Regular Package') {
                if (package.promo_type === 'percentage') {
                    // Calculate percentage discount
                    discount = subtotal * (parseFloat(package.percentage) / 100);
                } else if (package.enrollment_fee) {
                    // Use fixed enrollment fee as discount
                    discount = parseFloat(package.enrollment_fee);
                }

                // Handle custom initial payment for options 3-4
                if (parseInt(package.selection_type) > 2 && package.custom_initial_payment) {
                    // For custom payment options, use the custom initial payment
                    discount = subtotal - parseFloat(package.custom_initial_payment);
                }

                $('#promoDiscount').show().text(`Promo Discount: -₱${discount.toLocaleString()}`);
                $('#promoInfo').show().html(`
            <strong>Package Discount:</strong> ₱${discount.toLocaleString()}<br>
            <small>${package.package_name} - Option ${package.selection_type}</small>
        `);
            } else {
                $('#promoDiscount').hide();
                $('#promoInfo').hide();
            }

            const finalTotal = subtotal - discount;
            $('#subtotalAmount').text(`₱${subtotal.toLocaleString()}`);
            $('#totalAmount, #totalAmountDisplay').text(`₱${finalTotal.toLocaleString()}`);
            $('#finalTotalHidden').val(finalTotal);
            $('#subtotalHidden').val(subtotal);
            $('#promoAppliedHidden').val(discount);

            return finalTotal;
        };





        // Enhanced: Schedule validation with reservation handling and promo awareness
        const validateScheduleSelection = () => {
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const learningMode = $('input[name="learning_mode"]:checked').val();


            if (paymentType === 'initial_payment') {
                // FIXED: Get schedule data with proper error handling
                const scheduleJson = $('#hiddenSchedule').val() || '[]';
                let currentSchedules = [];
                let schedulesValid = false;

                // FIXED: Improved JSON parsing with better error handling
                try {
                    currentSchedules = JSON.parse(scheduleJson);
                    if (!Array.isArray(currentSchedules)) {
                        console.warn('⚠️ Schedule data is not an array, resetting to empty array');
                        currentSchedules = [];
                    }
                } catch (e) {

                    currentSchedules = [];
                }

                // FIXED: Check maintained schedules with proper validation
                let maintainedValid = false;
                if (maintainedSchedules && Array.isArray(maintainedSchedules) && maintainedSchedules.length > 0) {
                    maintainedValid = true;

                }

                // FIXED: Check currently selected schedules
                let currentValid = false;
                if (currentSchedules && currentSchedules.length > 0) {
                    currentValid = true;

                }

                // FIXED: Check DOM checkboxes as final validation
                let domValid = false;
                const checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
                if (checkedBoxes && checkedBoxes.length > 0) {
                    domValid = true;

                }

                // FIXED: Any of these validation methods should pass
                schedulesValid = maintainedValid || currentValid || domValid;



                if (!schedulesValid) {
                    $('#scheduleWarning').show().text('⚠️ Initial payment requires schedule selection');
                    console.error('❌ Schedule validation failed for initial payment');
                    return false;
                } else {
                    // FIXED: If DOM has selections but hidden field is empty, update it
                    if (domValid && !currentValid) {
                        updateHiddenScheduleFromDOM();
                    }
                }
            }

            function updateHiddenScheduleFromDOM() {

                const newSelectedSchedules = [];

                document.querySelectorAll('.row-checkbox:checked').forEach(checkbox => {
                    const row = checkbox.closest('tr');
                    const rowId = row.getAttribute('data-row-id');

                    if (rowId) {
                        const scheduleData = {
                            id: rowId,
                            schedule_id: rowId,
                            week_description: row.cells[1].textContent.trim(),
                            weekDescription: row.cells[1].textContent.trim(),
                            training_date: row.cells[2].textContent.trim(),
                            trainingDate: row.cells[2].textContent.trim(),
                            start_time: row.cells[3].textContent.trim(),
                            startTime: row.cells[3].textContent.trim(),
                            end_time: row.cells[4].textContent.trim(),
                            endTime: row.cells[4].textContent.trim(),
                            day_of_week: row.cells[5].textContent.trim(),
                            dayOfWeek: row.cells[5].textContent.trim()
                        };

                        newSelectedSchedules.push(scheduleData);
                    }
                });

                // Update the global selectedSchedules array
                selectedSchedules = newSelectedSchedules;

                // Update the hidden field
                const scheduleJson = JSON.stringify(selectedSchedules);
                $('#hiddenSchedule').val(scheduleJson);


            }


            // FIXED: Enhanced reservation handling
            if (paymentType === 'reservation') {
                if (learningMode === 'F2F' || learningMode === 'Online') {
                    $('#scheduleWarning').hide();

                    // Uncheck and disable all checkboxes for reservation
                    document.querySelectorAll(".row-checkbox").forEach(el => {
                        el.checked = false;
                        el.disabled = true;
                    });

                    // Show info in alert div
                    const alertDiv = document.getElementById('alert');
                    if (alertDiv) {
                        alertDiv.innerText = 'Reservation mode: Schedules not required for F2F/Online learning';
                        document.getElementById("hiddenSchedule").value = "[]";
                        alertDiv.style.display = 'block';
                    }


                    return true;
                }

                // For other learning modes, check if schedules are available
                if (maintainedSchedules.length > 0) {
                    $('#scheduleWarning').hide();

                    return true;
                }

                const scheduleJson = $('#hiddenSchedule').val() || '[]';
                let currentSchedules = [];
                try {
                    currentSchedules = JSON.parse(scheduleJson);
                    if (!Array.isArray(currentSchedules)) {
                        currentSchedules = [];
                    }
                } catch (e) {
                    currentSchedules = [];
                }

                if (currentSchedules.length === 0) {
                    $('#scheduleWarning').show().text('⚠️ Reservation payment requires schedule selection');
                    console.error('❌ Schedule validation failed for reservation payment');
                    return false;
                }
            }

            $('#scheduleWarning').hide();

            return true;
        };

        // Enhanced: Update program with immediate display and promo awareness
        const updateProgram = (p) => {
            currentProgram = p;

            // Enhanced: Show details immediately
            showProgramDetailsImmediately(p);

            $('#programDetailsHidden').val(JSON.stringify(p));
            calculateTotal(p, currentPackage);


        };

        // ENHANCED: Payment calculation with corrected change logic and promo validation
        function updatePaymentAmountsEnhanced() {
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const demoType = $('#demoSelect').val();
            let amount = 0;

            if (!currentProgram) return;

            // Show payment processing note
            if (paymentType) {
                if (paymentType === 'reservation' || paymentType === 'initial_payment') {
                    $('#paymentNote').show().text('Payment processing - cash drawer not required');
                } else {
                    $('#paymentNote').show().text('Payment processing - cash drawer optional (will auto-open if connected)');
                }
            } else {
                $('#paymentNote').hide();
            }

            // Validate schedule selection
            if (paymentType) {
                validateScheduleSelection();
            }

            // Calculate payment amounts based on type
            switch (paymentType) {
                case 'full_payment':
                    if (currentBalance > 0) {
                        amount = currentBalance;
                    } else {
                        amount = parseFloat($('#finalTotalHidden').val()) || parseFloat(currentProgram.total_tuition);
                    }
                    break;
                case 'initial_payment':
                    if (currentPackage && currentPackage.selection_type > 2 && currentPackage.custom_initial_payment) {
                        amount = parseFloat(currentPackage.custom_initial_payment);
                    } else {
                        amount = parseFloat(currentProgram.initial_fee || 0);
                    }
                    break;
                case 'demo_payment':
                    // FIXED: Always calculate demo fee even if no demo type selected yet
                    if (demoType) {
                        amount = calculateDemoFeeJS();

                    } else {
                        // Still show a demo fee even without selection for reference
                        amount = calculateDemoFeeJS();

                    }
                    break;
                case 'reservation':
                    amount = parseFloat(currentProgram.reservation_fee || 0);
                    break;
            }

            // Auto-fill total payment if empty
            if (!$('#totalPayment').val() || $('#totalPayment').val() == '0') {
                $('#totalPayment').val(amount.toFixed(2));
            }

            $('#cashToPay').val($('#totalPayment').val());

            // Calculate change
            const cash = parseFloat($('#cash').val()) || 0;
            const cashToPay = parseFloat($('#cashToPay').val()) || 0;
            const change = Math.max(0, cash - cashToPay);
            $('#change').val(change.toFixed(2));

            updateDemoFeesDisplay();
            updateDebugInfo();


        }

        function validateDemoPayment() {
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const demoType = $('#demoSelect').val();
            const totalPayment = parseFloat($('#totalPayment').val()) || 0;
            const expectedDemoFee = calculateDemoFeeJS();

            if (paymentType === 'demo_payment') {
                if (Math.abs(totalPayment - expectedDemoFee) > 0.01) { // Allow for small rounding differences
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Demo Payment Amount',
                        text: `The demo payment amount should be ₱${expectedDemoFee.toFixed(2)}. Please adjust the amount.`,
                        confirmButtonText: 'OK'
                    });
                    return false;
                }
            }
            return true;
        }

        async function showDemoExcessPaymentModal(paymentAmount, requiredAmount, excessAmount, demoType) {
            return new Promise((resolve) => {
                const modalContent = `
            <div style="text-align: left; padding: 20px;">
                <div style="background: #e3f2fd; border: 1px solid #2196F3; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; color: #1565C0;">
                        <i class="fa-solid fa-coins"></i> Demo Payment Excess Detected
                    </h4>
                    <div style="font-size: 14px; line-height: 1.6;">
                        <strong>Demo Type:</strong> ${demoType.replace('demo', 'Demo ').replace(/(\d)/, '$1')}<br>
                        <strong>Required Amount:</strong> ₱${requiredAmount.toLocaleString()}<br>
                        <strong>Amount Paid:</strong> ₱${paymentAmount.toLocaleString()}<br>
                        <strong style="color: #d63031;">Excess Amount:</strong> ₱${excessAmount.toLocaleString()}
                    </div>
                </div>
                
                <p style="margin-bottom: 20px; font-weight: 500; color: #333;">
                    You have paid more than required for this demo. How would you like to allocate the excess payment?
                </p>
                
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <button class="demo-excess-option" data-option="allocate_to_next_demo" 
                            style="padding: 18px; border: 2px solid #4CAF50; background: #e8f5e9; border-radius: 10px; cursor: pointer; text-align: left; transition: all 0.3s; position: relative;">
                        <div style="font-weight: bold; color: #2e7d32; margin-bottom: 8px; font-size: 16px;">
                            🎯 Allocate to Next Demo
                        </div>
                        <div style="color: #2e7d32; font-size: 14px; line-height: 1.4;">
                            Apply the excess ₱${excessAmount.toLocaleString()} to the next unpaid demo session.
                            This will reduce the amount needed for your next demo payment.
                        </div>
                        <div style="position: absolute; top: 15px; right: 15px; background: #4CAF50; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                            1
                        </div>
                    </button>
                    
                    <button class="demo-excess-option" data-option="credit_to_account" 
                            style="padding: 18px; border: 2px solid #2196F3; background: #e3f2fd; border-radius: 10px; cursor: pointer; text-align: left; transition: all 0.3s; position: relative;">
                        <div style="font-weight: bold; color: #1565C0; margin-bottom: 8px; font-size: 16px;">
                            💰 Credit to Student Account
                        </div>
                        <div style="color: #1565C0; font-size: 14px; line-height: 1.4;">
                            Save the excess ₱${excessAmount.toLocaleString()} as account credit.
                            Can be used for future payments, demos, or other program expenses.
                        </div>
                        <div style="position: absolute; top: 15px; right: 15px; background: #2196F3; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                            2
                        </div>
                    </button>
                    
                    <button class="demo-excess-option" data-option="return_as_change" 
                            style="padding: 18px; border: 2px solid #FF9800; background: #fff3e0; border-radius: 10px; cursor: pointer; text-align: left; transition: all 0.3s; position: relative;">
                        <div style="font-weight: bold; color: #F57C00; margin-bottom: 8px; font-size: 16px;">
                            💵 Return as Change
                        </div>
                        <div style="color: #F57C00; font-size: 14px; line-height: 1.4;">
                            Return the excess ₱${excessAmount.toLocaleString()} as cash change to the customer.
                            Only the required demo amount will be processed.
                        </div>
                        <div style="position: absolute; top: 15px; right: 15px; background: #FF9800; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold;">
                            3
                        </div>
                    </button>
                </div>
                
                <div style="margin-top: 20px; padding: 12px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #6c757d;">
                    <small style="color: #6c757d; font-style: italic;">
                        💡 Tip: Option 1 is recommended if you plan to pay for more demos. 
                        Option 2 is best for long-term students. Option 3 if you want immediate cash back.
                    </small>
                </div>
            </div>
        `;

                Swal.fire({
                    title: 'Allocate Demo Excess Payment',
                    html: modalContent,
                    width: '650px',
                    showCancelButton: true,
                    showConfirmButton: false,
                    cancelButtonText: 'Cancel Payment',
                    allowOutsideClick: false,
                    customClass: {
                        container: 'demo-excess-modal',
                        title: 'demo-excess-title'
                    },
                    didOpen: () => {
                        // Add hover effects and click handlers
                        document.querySelectorAll('.demo-excess-option').forEach(btn => {
                            btn.addEventListener('mouseenter', () => {
                                btn.style.transform = 'translateY(-3px)';
                                btn.style.boxShadow = '0 8px 25px rgba(0,0,0,0.15)';
                                btn.style.borderWidth = '3px';
                            });

                            btn.addEventListener('mouseleave', () => {
                                btn.style.transform = 'translateY(0)';
                                btn.style.boxShadow = 'none';
                                btn.style.borderWidth = '2px';
                            });

                            btn.addEventListener('click', async () => {
                                const option = btn.dataset.option;

                                // Show confirmation with preview
                                const preview = await showDemoAllocationPreview(option, excessAmount, demoType, requiredAmount);

                                if (preview) {
                                    Swal.close();

                                    const result = {
                                        choice: option,
                                        excessAmount: excessAmount,
                                        paymentAmount: paymentAmount,
                                        requiredAmount: requiredAmount,
                                        demoType: demoType,
                                        description: getDemoExcessDescription(option, excessAmount, demoType),
                                        allocationDetails: preview
                                    };


                                    resolve(result);
                                }
                            });
                        });
                    }
                }).then((result) => {
                    if (result.isDismissed) {

                        resolve(null); // Cancel payment
                    }
                });
            });
        }
        async function showDemoAllocationPreview(option, excessAmount, currentDemo, requiredAmount) {
            return new Promise((resolve) => {
                let previewContent = '';

                switch (option) {
                    case 'allocate_to_next_demo':
                        const nextDemo = findNextUnpaidDemoForPreview(currentDemo);
                        if (nextDemo) {
                            previewContent = `
                        <div style="text-align: left;">
                            <h4 style="color: #2e7d32; margin-bottom: 15px;">
                                <i class="fa-solid fa-preview"></i> Allocation Preview
                            </h4>
                            <div style="background: #e8f5e9; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <div style="margin-bottom: 10px;">
                                    <strong>Current Demo (${currentDemo.replace('demo', 'Demo ')}):</strong> ₱${requiredAmount.toLocaleString()} ✅ Will be marked as paid
                                </div>
                                <div style="margin-bottom: 10px;">
                                    <strong>Next Demo (${nextDemo.replace('demo', 'Demo ')}):</strong> ₱${excessAmount.toLocaleString()} will be credited
                                </div>
                                <div style="color: #2e7d32; font-weight: bold;">
                                    Total processed: ₱${(requiredAmount + excessAmount).toLocaleString()}
                                </div>
                            </div>
                            <p>Proceed with this allocation?</p>
                        </div>
                    `;
                        } else {
                            previewContent = `
                        <div style="text-align: left;">
                            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <strong>⚠️ No Next Demo Available</strong><br>
                                All demos appear to be paid. The excess will be returned as change instead.
                            </div>
                            <p>Proceed with returning ₱${excessAmount.toLocaleString()} as change?</p>
                        </div>
                    `;
                        }
                        break;

                    case 'credit_to_account':
                        previewContent = `
                    <div style="text-align: left;">
                        <h4 style="color: #1565C0; margin-bottom: 15px;">
                            <i class="fa-solid fa-piggy-bank"></i> Account Credit Preview
                        </h4>
                        <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <div style="margin-bottom: 10px;">
                                <strong>Demo Payment:</strong> ₱${requiredAmount.toLocaleString()} ✅ Will be processed
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong>Account Credit:</strong> ₱${excessAmount.toLocaleString()} will be saved
                            </div>
                            <div style="color: #1565C0; font-weight: bold;">
                                Available for future: Demos, fees, or other program expenses
                            </div>
                        </div>
                        <p>Proceed with crediting ₱${excessAmount.toLocaleString()} to student account?</p>
                    </div>
                `;
                        break;

                    case 'return_as_change':
                        previewContent = `
                    <div style="text-align: left;">
                        <h4 style="color: #F57C00; margin-bottom: 15px;">
                            <i class="fa-solid fa-hand-holding-usd"></i> Change Return Preview
                        </h4>
                        <div style="background: #fff3e0; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <div style="margin-bottom: 10px;">
                                <strong>Demo Payment:</strong> ₱${requiredAmount.toLocaleString()} ✅ Will be processed
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong>Cash Change:</strong> ₱${excessAmount.toLocaleString()} will be returned
                            </div>
                            <div style="color: #F57C00; font-weight: bold;">
                                Customer receives immediate cash back
                            </div>
                        </div>
                        <p>Proceed with returning ₱${excessAmount.toLocaleString()} as change?</p>
                    </div>
                `;
                        break;
                }

                Swal.fire({
                    title: 'Confirm Allocation',
                    html: previewContent,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Proceed',
                    cancelButtonText: 'Go Back',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    resolve(result.isConfirmed);
                });
            });
        }

        function findNextUnpaidDemoForPreview(currentDemo) {
            const demos = ['demo1', 'demo2', 'demo3', 'demo4'];
            const currentIndex = demos.indexOf(currentDemo);

            // Check paid demos from global variable
            for (let i = currentIndex + 1; i < demos.length; i++) {
                if (!paidDemos.includes(demos[i])) {
                    return demos[i];
                }
            }

            return null;
        }
        function getDemoExcessDescription(option, excessAmount, demoType) {
            const descriptions = {
                'allocate_to_next_demo': `₱${excessAmount.toLocaleString()} allocated to next demo`,
                'credit_to_account': `₱${excessAmount.toLocaleString()} credited to student account`,
                'return_as_change': `₱${excessAmount.toLocaleString()} returned as change`
            };

            return descriptions[option] || 'Demo excess processed';
        }


        window.updatePaymentData = updatePaymentAmountsEnhanced;

        const populateSchedule = (schedules) => {
            const tbody = $('#scheduleTable tbody').empty();
            const paymentType = $('input[name="type_of_payment"]:checked').val();

            if (!schedules?.length) {
                tbody.append('<tr><td colspan="6" class="border px-2 py-1 text-center text-gray-500">No schedules available</td></tr>');
                return;
            }

            const shouldLockSchedules = paymentType === 'reservation' && maintainedSchedules.length > 0;

            if (shouldLockSchedules) {
                $('#scheduleMaintained').show().text('Schedules locked for reservation payment');
            } else if (maintainedSchedules.length > 0 && paymentType !== 'reservation') {
                $('#scheduleMaintained').show().text('Schedules maintained (can be modified)');
            } else {
                $('#scheduleMaintained').hide();
            }

            schedules.forEach(s => {
                const isMaintained = maintainedSchedules.length > 0 &&
                    maintainedSchedules.some(ms => ms.id == s.id || ms.schedule_id == s.id);

                const isDisabled = paymentType === 'reservation' && isMaintained;
                const isChecked = isMaintained ? 'checked' : '';
                const rowClass = isMaintained && paymentType === 'reservation' ? 'schedule-locked' :
                    isMaintained ? 'schedule-maintained' : '';

                tbody.append(`
            <tr data-row-id="${s.id}" class="${rowClass}">
                <td class="border px-2 py-1">
                    <input type="checkbox" class="row-checkbox"
                        onchange="handleRowSelection(this)"
                        ${isDisabled ? 'disabled' : ''}
                        ${isChecked} />
                    ${isMaintained && paymentType === 'reservation' ?
                        '<i class="fa-solid fa-lock text-red-600 ml-1" title="Schedule Locked for Reservation"></i>' : ''}
                    ${isMaintained && paymentType !== 'reservation' ?
                        '<i class="fa-solid fa-edit text-blue-600 ml-1" title="Can be Modified for ' + paymentType + '"></i>' : ''}
                </td>
                ${[s.week_description, s.training_date, s.start_time, s.end_time, s.day_of_week]
                        .map(val => `<td class="border px-2 py-1">${val || ''}</td>`).join('')}
            </tr>
            `);
            });

            if (maintainedSchedules.length > 0) {
                selectedSchedules = maintainedSchedules.map(ms => ({
                    id: ms.id || ms.schedule_id,
                    week_description: ms.week_description || ms.weekDescription || '',
                    training_date: ms.training_date || ms.trainingDate || '',
                    start_time: ms.start_time || ms.startTime || '',
                    end_time: ms.end_time || ms.endTime || '',
                    day_of_week: ms.day_of_week || ms.dayOfWeek || ''
                }));

                $('#hiddenSchedule').val(JSON.stringify(selectedSchedules));
            }

            updateDebugInfo();
        };

        const resetProgram = () => {
            currentProgram = null;
            currentPackage = null;
            $('#programTitle').text('Care Pro');
            $('#programName').text('Select a program to view details');
            ['#learningMode', '#assessmentFee', '#tuitionFee', '#miscFee', '#otherFees', '#packageInfo', '#promoInfo', '#promoDiscount', '#scheduleWarning', '#paymentNote', '#scheduleMaintained'].forEach(s => $(s).hide());
            $('#totalAmount, #subtotalAmount, #totalAmountDisplay').text('₱0');
            $('#programDetailsHidden').val('');
            $('#totalPayment, #cashToPay, #cash, #change').val('');
            $('#scheduleTableContainer').show();

            // Enhanced: Hide promo selection info and reset charges
            hidePromoSelectionInfo();

            if ($('#chargesPlaceholder').length === 0) {
                $('#chargesContainer').html(`
            <div id="chargesPlaceholder">
                <h1 class="font-semibold text-xl mb-2">Charges</h1>
                <div class="text-center text-gray-500 py-8">
                    <i class="fa-solid fa-graduation-cap text-4xl mb-4"></i>
                    <p>Select a program to view charges and demo fees immediately</p>
                </div>
            </div>
        `);
            }


        };

        const resetSchedule = () => {
            $('#scheduleTable tbody').html('<tr><td colspan="6" class="border px-2 py-1 text-center text-gray-500">Select a program to view schedules</td></tr>');
            if (maintainedSchedules.length === 0) {
                selectedSchedules = [];
                $('#hiddenSchedule').val('[]');
            }
            $('#scheduleMaintained').hide();
            $('#scheduleTableContainer').show();
            updateDebugInfo();
        };

        const loadAllPrograms = (callback = null) => {
            ajax('functions/ajax/get_program.php', {}, data => {
                allPrograms = data;

                if (callback) {
                    callback();
                }
            });
        };

        const loadPrograms = () => {
            loadAllPrograms(() => {
                populateSelect('#programSelect', allPrograms, 'id', 'program_name', 'Select a program', null, 'learning_mode');

                const selectedLearningMode = $('input[name="learning_mode"]:checked').val();
                if (selectedLearningMode) {
                    filterProgramsByLearningMode(selectedLearningMode);
                }

                if (existingTransaction.program_id) {
                    $('#programSelect').val(existingTransaction.program_id).trigger('change');
                }
            });
        };

        const loadProgramDetails = (id) => ajax('functions/ajax/get_program_details.php', { program_id: id }, updateProgram);

        const loadPackages = (id) => {
            ajax('functions/ajax/get_packages.php', { program_id: id }, function (data) {
                $('#packageSelect').empty().append('<option value="">Select a package</option>');

                // Add existing package first if available 
                if (existingTransaction && existingTransaction.package_name) {
                    $('#packageSelect').append(
                        $('<option>', {
                            value: existingTransaction.package_name,
                            text: existingTransaction.package_name
                        })
                    );
                }

                // Add packages from data
                if (Array.isArray(data)) {
                    data.forEach(function (package) {
                        if (!existingTransaction || package.package_name !== existingTransaction.package_name) {
                            $('#packageSelect').append(
                                $('<option>', {
                                    value: package.package_name,
                                    text: "Package: " + package.package_name
                                })
                            );
                        }
                    });
                }

                // Add Regular Package option if not already selected
                if (!existingTransaction || existingTransaction.package_name !== 'Regular Package') {
                    $('#packageSelect').append(
                        $('<option>', {
                            value: 'Regular Package',
                            text: 'Regular Package'
                        })
                    );
                }

                // Set selected value if exists
                if (existingTransaction && existingTransaction.package_name) {
                    $('#packageSelect').val(existingTransaction.package_name).trigger('change');
                }

                // Handle enrollment lock
                if (typeof enrollmentLocked !== 'undefined' && enrollmentLocked) {
                    $('#packageSelect').prop('disabled', true);
                    console.log(`[${new Date().toISOString()}] Package selection locked - User: scrapper22`);
                }
            });
        };

        // Enhanced: Disable reservation for promo packages with validation
        function disableReservationIfPromo() {
            const selectedPackage = $('#packageSelect').val();
            const isPromo = selectedPackage && selectedPackage !== 'Regular Package';

            const $reservationRadio = $('input[type="radio"][name="type_of_payment"][value="reservation"]');
            const $reservationLabel = $reservationRadio.closest('label');

            if (isPromo) {
                $reservationRadio.prop('disabled', true).prop('checked', false);
                $reservationLabel.css('color', '#b91c1c');
                if ($('#reservationPromoMsg').length === 0) {
                    $reservationLabel.append(
                        '<span id="reservationPromoMsg" style="color:#b91c1c; font-size:12px; margin-left:8px;">Reservation is not available for promo packages.</span>'
                    );
                }
            } else {
                $reservationRadio.prop('disabled', false);
                $reservationLabel.css('color', '');
                $('#reservationPromoMsg').remove();
            }
        }

        const loadSchedules = (id) => ajax('functions/ajax/get_schedules.php', { program_id: id }, populateSchedule);


        const validatePaymentTypes = () => {
            paidPaymentTypes.forEach(paymentType => {
                if (paymentType !== 'demo_payment') {
                    const $option = $(`label[data-payment-type="${paymentType}"]`);
                    const $input = $(`input[name="type_of_payment"][value="${paymentType}"]`);
                    const $status = $option.find('.payment-status');

                    $option.addClass('payment-hidden');
                    $input.prop('disabled', true);
                    $status.text('(Already paid)').show();
                }
            });

            populateDemoSelect();
        };

        // =============================================================================================
        // ENHANCED: EVENT HANDLERS WITH PROMO SELECTION INTEGRATION
        // =============================================================================================

        // Enhanced: Program selection handler
        $('#programSelect').change(function () {
            const id = $(this).val();
            if (id) {
                loadProgramDetails(id);
                loadPackages(id);
                loadSchedules(id);
            } else {
                resetProgram();
                $('#packageSelect').html('<option value="">Select a program first</option>').prop('disabled', true);
                resetSchedule();
            }
        });

        // Enhanced: Package selection handler with promo selection display
        $('#packageSelect').change(function () {
            const packageName = $(this).val();
            if (!packageName) {
                currentPackage = null;
                $('#packageInfo').text('');
                $('#packageDetailsHidden').val('');
                if (currentProgram) {
                    calculateTotal(currentProgram, null);
                    updateProgram(currentProgram);
                }
                return;
            }

            if (packageName === 'Regular Package') {
                currentPackage = null;
                $('#packageInfo').text(`Package: Regular Package`);
                $('#packageDetailsHidden').val('{}');
                if (currentProgram) {
                    calculateTotal(currentProgram, null);
                    updateProgram(currentProgram);
                }
                return;
            }

            // Get package details and update calculation
            $.ajax({
                url: 'functions/ajax/get_package_details.php',
                type: 'GET',
                data: {
                    program_id: currentProgram.id,
                    package_name: packageName
                },
                success: function (packageData) {
                    currentPackage = packageData;
                    $('#packageInfo').text(`Package: ${packageName}`);
                    $('#packageDetailsHidden').val(JSON.stringify(packageData));

                    // Update calculations immediately
                    if (currentProgram) {
                        calculateTotal(currentProgram, packageData);
                        updateProgram(currentProgram);

                        // Show package info
                        if (packageData.selection_type > 2) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Custom Payment Package',
                                html: `
                            <div class="text-left">
                                <p>Selected package requires custom initial payment:</p>
                                <p class="font-bold">₱${parseFloat(packageData.custom_initial_payment).toLocaleString()}</p>
                            </div>
                        `,
                                confirmButtonText: 'OK'
                            });
                        }
                    }
                },
                error: function () {
                    Swal.fire({
                        icon: 'error',
                        title: 'Package Error',
                        text: 'Failed to load package details. Please try again.'
                    });
                }
            });
        });


        disableReservationIfPromo();
        $('input[name="learning_mode"]').change(function () {
            const mode = $(this).val();
            $('#learningMode').text(`Learning Mode: ${mode}`);

            filterProgramsByLearningMode(mode);

            if (currentProgram) {
                showProgramDetailsImmediately(currentProgram);
                updateDemoFeesDisplay();
            }
        });


        // Enhanced: Payment type change handler with demo selection preservation and promo validation
        $('input[name="type_of_payment"]').change(function () {
            const paymentType = $(this).val();
            const currentDemoSelection = $('#demoSelect').val(); // Enhanced: PRESERVE SELECTION

            // Enhanced: Validate custom initial payments for options 3-4
            if (paymentType === 'initial_payment' && currentPackage && currentPackage.selection_type > 2) {
                const customInitial = parseFloat(currentPackage.custom_initial_payment || 0);
                Swal.fire({
                    icon: 'info',
                    title: 'Custom Initial Payment Required',
                    html: `
            <div style="text-align: left;">
                <p><strong>Package:</strong> ${currentPackage.package_name}</p>
                <p><strong>Selection Option:</strong> ${currentPackage.selection_type}</p>
                <p><strong>Required Initial Payment:</strong> ₱${customInitial.toLocaleString()}</p>
                <hr style="margin: 10px 0;">
                    <small>This amount was declared during program setup and cannot be modified.</small>
            </div>
            `,
                    confirmButtonText: 'I Understand',
                    timer: 8000
                });
            }

            if (paymentType === 'demo_payment') {
                $('#demoSelection').show();
                const hasAvailableDemos = populateDemoSelect(true); // Enhanced: PRESERVE SELECTION

                // Enhanced: Restore selection if it was cleared
                if (currentDemoSelection && $('#demoSelect option[value="' + currentDemoSelection + '"]').length) {
                    setTimeout(() => {
                        $('#demoSelect').val(currentDemoSelection);
                    }, 100);
                }

                if (!hasAvailableDemos) {
                    Swal.fire({
                        icon: 'info',
                        title: 'All Demos Completed',
                        text: 'All practical demos have been paid for this student.',
                        confirmButtonText: 'I understand'
                    });
                    $(this).prop('checked', false);
                    return;
                }
            } else {
                $('#demoSelection').hide();
                // Enhanced: DON'T clear the selection, just hide the container
            }

            if (currentProgram) {
                loadSchedules(currentProgram.id);
            }

            $('#totalPayment').val('');
            updatePaymentAmountsEnhanced();
        });

        $('#demoSelect').change(function () {
            $('#totalPayment').val('');
            updatePaymentAmountsEnhanced();
        });

        // ENHANCED: Proper change calculation on input events
        $('#totalPayment').on('input', function () {
            const amount = parseFloat($(this).val()) || 0;
            $('#cashToPay').val(amount.toFixed(2));

            const cash = parseFloat($('#cash').val()) || 0;
            const change = Math.max(0, cash - amount); // Ensure change is never negative
            $('#change').val(change.toFixed(2));
        });

        $('#cash').on('input', function () {
            const cash = parseFloat($(this).val()) || 0;
            const cashToPay = parseFloat($('#cashToPay').val()) || 0;
            const change = Math.max(0, cash - cashToPay); // Ensure change is never negative
            $('#change').val(change.toFixed(2));
        });

        $('#cashToPay').on('input', function () {
            const cashToPay = parseFloat($(this).val()) || 0;
            const cash = parseFloat($('#cash').val()) || 0;
            const change = Math.max(0, cash - cashToPay); // Ensure change is never negative
            $('#change').val(change.toFixed(2));
        });

        window.handleRowSelection = function (checkbox) {
            const row = checkbox.closest('tr');
            const rowId = row.getAttribute('data-row-id');
            const paymentType = $('input[name="type_of_payment"]:checked').val();

            // First, update the selected schedules array
            selectedSchedules = []; // Reset the array each time to track only checked boxes

            // Get all checked checkboxes and build the schedules array
            document.querySelectorAll('.row-checkbox:checked').forEach(checkedBox => {
                const selectedRow = checkedBox.closest('tr');
                const scheduleId = selectedRow.getAttribute('data-row-id');

                const scheduleData = {
                    id: scheduleId,
                    schedule_id: scheduleId,
                    week_description: selectedRow.cells[1].textContent.trim(),
                    weekDescription: selectedRow.cells[1].textContent.trim(),
                    training_date: selectedRow.cells[2].textContent.trim(),
                    trainingDate: selectedRow.cells[2].textContent.trim(),
                    start_time: selectedRow.cells[3].textContent.trim(),
                    startTime: selectedRow.cells[3].textContent.trim(),
                    end_time: selectedRow.cells[4].textContent.trim(),
                    endTime: selectedRow.cells[4].textContent.trim(),
                    day_of_week: selectedRow.cells[5].textContent.trim(),
                    dayOfWeek: selectedRow.cells[5].textContent.trim()
                };

                selectedSchedules.push(scheduleData);
            });

            // Update the hidden input with the current state
            const scheduleJson = JSON.stringify(selectedSchedules);
            $('#hiddenSchedule').val(scheduleJson);

            // Update row styling
            if (checkbox.checked) {
                row.classList.add('bg-blue-50');
            } else {
                row.classList.remove('bg-blue-50');
            }

            // Update debug info if visible
            if ($('#debugInfo').is(':visible')) {
                updateDebugInfo();
            }

            // Validate schedule selection
            validateScheduleSelection();

            console.log(`[${new Date().toISOString()}] Schedule selection updated by ${currentUser}:`, {
                selectedSchedules: selectedSchedules,
                hiddenFieldValue: $('#hiddenSchedule').val(),
                checkedBoxesCount: document.querySelectorAll('.row-checkbox:checked').length
            });
        };
        // Enhanced: Form submission with promo selection validation and proper change calculation
        // FIXED: Enhanced form submission with better schedule validation
        // ENHANCED: Form submission with excess payment handling
        $('#posForm').submit(async function (e) {
            e.preventDefault();

            const paymentType = $('input[name="type_of_payment"]:checked').val();
            const totalPayment = parseFloat($('#totalPayment').val()) || 0;
            const demoType = $('#demoSelect').val();
            const studentId = $('input[name="student_id"]').val() || "";
            const programId = $('#programSelect').val() || "";
            const cash = parseFloat($('#cash').val()) || 0;

            // Basic validation
            if (!studentId || !programId || !paymentType || totalPayment <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill in all required fields and enter a valid payment amount.'
                });
                return;
            }

            // Schedule validation for non-reservation payments
            if (paymentType !== 'reservation') {
                const scheduleJson = $('#hiddenSchedule').val() || '[]';
                let selectedSchedules = [];
                try {
                    selectedSchedules = JSON.parse(scheduleJson);
                } catch (e) {
                    console.error('Schedule parsing error:', e);
                }

                if (!Array.isArray(selectedSchedules) || selectedSchedules.length === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Schedule Required',
                        text: 'Please select at least one schedule before proceeding. Only reservation payments can proceed without schedule selection.',
                        confirmButtonText: 'OK'
                    });
                    return;
                }
            }

            // Validate initial payment requirement for demo payments
            if (paymentType === 'demo_payment') {
                // Check if initial payment exists in paid payment types
                const hasInitialPayment = paidPaymentTypes.includes('initial_payment');

                if (!hasInitialPayment) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Initial Payment Required',
                        html: `
                    <div class="text-left">
                        <p class="mb-2">Cannot process demo payment without initial payment.</p>
                        <p class="mb-2">Please process the initial payment first before proceeding with demo payments.</p>
                        <hr class="my-3">
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-info-circle"></i> Payment order:
                        </p>
                        <ol class="list-decimal ml-4 text-sm">
                            <li>Initial Payment</li>
                            <li>Demo Payments</li>
                        </ol>
                    </div>
                `,
                        confirmButtonText: 'I Understand'
                    });
                    return;
                }
            }

            // ENHANCED: Check for excess payment (including demo payments)
            let excessData = null;

            if (paymentType === 'demo_payment' && demoType) {
                const requiredAmount = calculateDemoFeeJS();

                if (totalPayment > requiredAmount && requiredAmount > 0) {
                    const excessAmount = totalPayment - requiredAmount;
                    excessData = await showDemoExcessPaymentModal(totalPayment, requiredAmount, excessAmount, demoType);

                    if (!excessData) {
                        return; // User cancelled
                    }
                }
            } else if (paymentType === 'initial_payment') {
                const requiredAmount = currentPackage && currentPackage.selection_type > 2 && currentPackage.custom_initial_payment
                    ? parseFloat(currentPackage.custom_initial_payment)
                    : parseFloat(currentProgram?.initial_fee || 0);

                if (totalPayment > requiredAmount && requiredAmount > 0) {
                    const excessAmount = totalPayment - requiredAmount;
                    excessData = await showExcessPaymentModal(totalPayment, requiredAmount, excessAmount, paymentType);

                    if (!excessData) {
                        return; // User cancelled
                    }
                }
            }

            // Show processing indicator
            const submitBtn = $(this).find('button[type="submit"]');
            const originalBtnText = submitBtn.html();
            submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin mr-2"></i>Processing...');

            // Create form data with excess payment information
            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('program_id', programId);
            formData.append('learning_mode', $('input[name="learning_mode"]:checked').val());
            formData.append('type_of_payment', paymentType);
            formData.append('package_id', $('#packageSelect').val() || 'Regular');

            // Add demo type for demo payments
            if (paymentType === 'demo_payment' && demoType) {
                formData.append('demo_type', demoType);
            }

            // Add excess payment data if present
            if (excessData) {
                formData.append('has_excess_payment', 'true');
                formData.append('excess_choice', excessData.choice);
                formData.append('excess_amount', excessData.excessAmount.toString());
                formData.append('original_payment_amount', excessData.paymentAmount.toString());
                formData.append('required_payment_amount', excessData.requiredAmount.toString());

                // Add demo-specific data
                if (paymentType === 'demo_payment') {
                    formData.append('excess_demo_type', excessData.demoType);
                    if (excessData.allocationDetails) {
                        formData.append('excess_allocations', JSON.stringify(excessData.allocationDetails));
                    }
                }
            } else {
                formData.append('has_excess_payment', 'false');
            }

            // Add other form data
            formData.append('selected_schedules', $('#hiddenSchedule').val() || '[]');
            formData.append('sub_total', $('#subtotalHidden').val() || '0');
            formData.append('final_total', $('#finalTotalHidden').val() || '0');
            formData.append('cash', cash.toString());
            formData.append('total_payment', totalPayment.toString());
            formData.append('promo_applied', $('#promoAppliedHidden').val() || '0');
            formData.append('program_details', $('#programDetailsHidden').val() || '{}');
            formData.append('package_details', $('#packageDetailsHidden').val() || '{}');

            // Submit to backend
            $.ajax({
                url: 'functions/ajax/process_enrollment.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                timeout: 30000,
                success: function (response) {
                    if (response.success) {
                        let successMessage = `Payment of ₱${totalPayment.toLocaleString()} processed successfully!`;

                        // Add excess payment information to success message
                        if (response.excess_processing && excessData) {
                            successMessage += `\n\n💰 Excess Payment Handled:`;
                            successMessage += `\n• Choice: ${excessData.choice.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}`;
                            successMessage += `\n• Excess Amount: ₱${excessData.excessAmount.toLocaleString()}`;
                            successMessage += `\n• Action: ${excessData.description}`;
                        }

                        // Add excess info to receipt if applicable
                        if (excessData) {
                            addExcessInfoToReceipt(excessData);
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Payment Successful!',
                            text: successMessage,
                            confirmButtonText: 'Print Receipt',
                            showCancelButton: true,
                            cancelButtonText: 'Continue',
                            timer: 8000
                        }).then((result) => {
                            if (result.isConfirmed) {
                                printReceiptSection2();
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        console.error(`❌ Payment failed:`, response.message);
                        Swal.fire({
                            icon: 'error',
                            title: 'Payment Failed',
                            text: response.message || 'Failed to process payment. Please try again.',
                            confirmButtonText: 'Try Again'
                        });
                    }
                },
                error: function (xhr, status, error) {
                    console.error(`❌ AJAX error:`, error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Failed to connect to server. Please check your connection and try again.',
                        confirmButtonText: 'Retry'
                    });
                },
                complete: function () {
                    // Re-enable submit button
                    submitBtn.prop('disabled', false).html(originalBtnText);
                }
            });
        });

        // ========================================================================================
        // ENHANCED SYSTEM INITIALIZATION WITH PROMO SELECTION SUPPORT
        // ========================================================================================

        const initializeSystem = async () => {
            // Initialize cash drawer auto-search (optional)

            // Check if initial payment has been made
            const hasInitialPayment = paidPaymentTypes.includes('initial_payment');
            const hasReservation = paidPaymentTypes.includes('reservation');

            if (enrollmentLocked) {
                handleEnrollmentLock();

                // Show notification about locked state
                Swal.fire({
                    icon: 'info',
                    title: 'Enrollment Options Locked',
                    html: `
                <div class="text-left">
                    <p>This student has existing enrollment records.</p>
                    <p>To maintain data consistency:</p>
                    <ul class="list-disc pl-5 mt-2">
                        <li>Program selection is locked</li>
                        <li>Package selection is locked</li>
                        <li>Learning mode is locked</li>
                    </ul>
                </div>
            `,
                    showConfirmButton: true,
                    confirmButtonText: 'Understood',
                    timer: 5000
                });
            }

            // Lock enrollment choices if no payment





            await autoSearchCashDrawer();

            validatePaymentTypes();

            if (existingTransaction.learning_mode) {
                $(`input[name="learning_mode"][value="${existingTransaction.learning_mode}"]`).prop('checked', true).trigger('change');
            }

            loadPrograms();
            updateDebugInfo();


        };

        initializeSystem();

        // Enhanced: Monitor system health with demo selection preservation and promo awareness
        setInterval(() => {
            const paymentType = $('input[name="type_of_payment"]:checked').val();
            if (paymentType) {
                validateScheduleSelection();
            }

            if (maintainedSchedules.length > 0) {
                const statusText = paymentType === 'reservation' ?
                    'Schedules locked for reservation payment' :
                    `Schedules from reservation (can be modified for ${paymentType || 'selected payment'})`;
                $('#scheduleMaintained').show().text(statusText);
            }

            // Enhanced: Only update demo select if demo payment is NOT currently selected
            if (paymentType !== 'demo_payment') {
                populateDemoSelect(true); // Always preserve selection
            }

            if ($('#debugInfo').is(':visible')) {
                updateDebugInfo();
            }

            updateCashDrawerStatus(cashDrawerConnected, cashDrawerConnected ? "Ready" : "Disconnected");

        }, 10000);

        // Cleanup on page unload
        window.addEventListener('beforeunload', async () => {
            if (monitoringInterval) {
                clearInterval(monitoringInterval);
            }
            if (cashDrawerConnected) {
                try {
                    await disconnectCashDrawer();
                } catch (error) {
                    // Silent cleanup
                }
            }
        });

        // ENHANCED: Disconnect function
        async function disconnectCashDrawer() {
            try {
                if (writer) {
                    await writer.close();
                    writer = null;
                }

                if (serialPort) {
                    await serialPort.close();
                    serialPort = null;
                }

                cashDrawerConnected = false;
                updateCashDrawerStatus(false, "Disconnected");

            } catch (error) {
                writer = null;
                serialPort = null;
                cashDrawerConnected = false;
                updateCashDrawerStatus(false, "Force Disconnected");
            }
        }

        // Enhanced: Expose enhanced functions globally with promo support
        window.cashDrawer = {
            autoSearch: autoSearchCashDrawer,
            requestNew: requestNewCashDrawerPort,
            connect: connectToCashDrawer,
            disconnect: async () => {
                if (monitoringInterval) {
                    clearInterval(monitoringInterval);
                    monitoringInterval = null;
                }
                await disconnectCashDrawer();
            },

            // Operation functions
            open: () => openCashDrawerOnPayment(0, "manual"),
            test: async () => {
                const result = await openCashDrawerOnPayment(0, "test");
                if (result) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Test Successful!',
                        text: 'Cash drawer opened successfully.',
                        timer: 2000
                    });
                }
                return result;
            },

            // Status functions
            status: () => ({
                connected: cashDrawerConnected,
                port: serialPort ? 'Available' : 'Not Available',
                writer: writer ? 'Ready' : 'Not Ready',
                monitoring: monitoringInterval ? 'Active' : 'Inactive',
                timestamp: '2025-06-21 03:25:31',
                user: 'Scraper001'
            }),

            // Utility functions
            isSupported: isWebSerialSupported,
            reconnect: async () => {
                await disconnectCashDrawer();
                setTimeout(async () => {
                    await autoSearchCashDrawer();
                }, 1000);
            }
        };

        // Enhanced: Expose demo calculator functions with promo awareness
        window.demoCalculator = {
            calculate: calculateDemoFeeJS,
            update: () => {
                if (currentProgram) {
                    updateDemoFeesDisplay();
                    showProgramDetailsImmediately(currentProgram);
                }
            },
            getStatus: () => ({
                currentBalance: currentBalance,
                paidDemos: paidDemos,
                remainingDemos: 4 - paidDemos.length,
                calculatedFee: calculateDemoFeeJS(),
                isFirstTransaction: isFirstTransaction,
                promoSelection: currentPackage ? `Option ${currentPackage.selection_type}` : 'No Promo',
                timestamp: '2025-06-21 03:25:31',
                user: 'Scraper001'
            }),
            recalculate: () => {
                if (currentProgram) {
                    updateDemoFeesDisplay();
                    showProgramDetailsImmediately(currentProgram);
                    updatePaymentAmountsEnhanced();
                }
            }
        };

        // Enhanced: Expose program display functions with promo integrationW
        window.programDisplay = {
            refresh: () => {
                if (currentProgram) {
                    showProgramDetailsImmediately(currentProgram);
                    updateDemoFeesDisplay();
                }
            },
            getStatus: () => ({
                programLoaded: !!currentProgram,
                isFirstTransaction: isFirstTransaction,
                detailsVisible: !!currentProgram,
                chargesVisible: !!currentProgram,
                demoFeesVisible: !!currentProgram,
                promoSelection: currentPackage ? `Option ${currentPackage.selection_type} - ${currentPackage.package_name}` : 'No Promo',
                timestamp: '2025-06-21 03:25:31',
                user: 'Scraper001'
            }),
            forceDisplay: () => {
                if (currentProgram) {
                    showProgramDetailsImmediately(currentProgram);
                    updateDemoFeesDisplay();
                    showSuccessNotification("Program Display", "Program details, charges, and demo fees are now visible immediately");
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'No Program Selected',
                        text: 'Please select a program first to display details.',
                        confirmButtonText: 'OK'
                    });
                }
            }
        };

        // Enhanced: Expose promo selection functions
        window.promoSelection = {
            getCurrentSelection: () => {
                if (!currentPackage) return null;
                return {
                    packageName: currentPackage.package_name,
                    selectionType: currentPackage.selection_type,
                    calculationMethod: currentPackage.selection_type <= 2 ? 'Percentage-based' : 'Manual payment',
                    percentage: currentPackage.percentage || 0,
                    customInitialPayment: currentPackage.custom_initial_payment || 0,
                    enrollmentFee: currentPackage.enrollment_fee || 0,
                    timestamp: '2025-06-21 03:25:31',
                    user: 'Scraper001'
                };
            },
            validateCustomPayment: (paymentAmount) => {
                if (!currentPackage || currentPackage.selection_type <= 2) return true;
                const customInitial = parseFloat(currentPackage.custom_initial_payment || 0);
                return Math.abs(paymentAmount - customInitial) <= 0.01;
            },
            getRequiredInitialPayment: () => {
                if (!currentPackage) return null;
                if (currentPackage.selection_type <= 2) {
                    return parseFloat(currentProgram?.initial_fee || 0);
                } else {
                    return parseFloat(currentPackage.custom_initial_payment || 0);
                }
            },
            displayInfo: () => {
                if (currentPackage) {
                    displayPromoSelectionInfo(currentPackage);
                } else {
                    hidePromoSelectionInfo();
                }
            }
        };

        // Enhanced: Expose reservation functions with promo awareness
        window.reservationSystem = {
            getStatus: () => ({
                cashDrawerBypass: true,
                scheduleHandling: 'Automatic for F2F/Online',
                validationStatus: 'Enhanced',
                promoRestrictions: 'Disabled for promo packages',
                timestamp: '2025-06-21 03:25:31',
                user: 'Scraper001'
            }),
            testReservation: () => {
                // Check if reservation is available
                const reservationOption = $('input[name="type_of_payment"][value="reservation"]');
                if (reservationOption.length && !reservationOption.prop('disabled')) {
                    reservationOption.prop('checked', true).trigger('change');
                    showSuccessNotification("Reservation Test", "Reservation payment type selected. Cash drawer will NOT be triggered.");
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Reservation Test',
                        text: 'Reservation payment type is not available (may already be paid or disabled for promo packages).',
                        confirmButtonText: 'OK'
                    });
                }
            },
            checkPromoRestriction: () => {
                const selectedPackage = $('#packageSelect').val();
                const isPromo = selectedPackage && selectedPackage !== 'Regular Package';
                return {
                    isRestricted: isPromo,
                    reason: isPromo ? 'Reservation not available for promo packages' : 'Reservation available',
                    promoPackage: selectedPackage || 'None'
                };
            }
        };

        // Add enhanced CSS animations with promo selection styling
        $('head').append(`
    <style>
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.02); }
        }
        
        @keyframes slideInDown {
            from {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }
        
        .cash-drawer-success { animation: slideInRight 0.4s ease-out; }
        
        #cashDrawerStatus { transition: all 0.3s ease; }
        
        #cashDrawerStatus:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        .processing-payment {
            background: linear-gradient(45deg, #667eea, #764ba2, #667eea);
            background-size: 200% 200%; 
            animation: gradientShift 2s ease infinite; 
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .demo-fee-item {
            transition: all 0.3s ease;
        }

        .demo-fee-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .first-transaction-highlight {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #0ea5e9;
            border-radius: 4px;
            padding: 8px;
            margin: 4px 0;
        }

        .schedule-locked {
            background-color: #fef2f2 !important;
            border-color: #fecaca;
        }

        .schedule-maintained {
            background-color: #f0f9ff !important;
            border-color: #bfdbfe;
        }

        .program-details-section,
        .charges-section,
        .demo-fees-display {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        .fixed-success-indicator {
            position: fixed;
            top: 50px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: bold;
            z-index: 10001;
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
            animation: slideInDown 0.5s ease-out;
        }

        /* Enhanced Promo Selection Animations */
        .promo-selection-display {
            transition: all 0.3s ease;
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .promo-option-1:hover, .promo-option-2:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .promo-option-3:hover, .promo-option-4:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .custom-payment-info {
            animation: slideInLeft 0.3s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
`);

        // Show enhanced system ready notifications with promo selection info
        setTimeout(() => {
            if (cashDrawerConnected) {
                showSuccessNotification("System Ready", "Cash drawer connected. Enhanced with Promo Selection Options (1-4).");
            }

            // Show enhanced fix status notification
            $('body').append(`
        <div class="fixed-success-indicator" id="fixedIndicator">
            <i class="fa-solid fa-check-circle mr-2"></i>Enhanced POS with Promo Selection (1-4) Ready!
        </div>
    `);

            setTimeout(() => {
                $('#fixedIndicator').fadeOut(1000, function () {
                    $(this).remove();
                });
            }, 6000);

        }, 2000);


    });

    // Enhanced: Global print function
    function printReceiptSection2() {
        const printContents = document.getElementById('receiptSection').innerHTML;
        const originalContents = document.body.innerHTML;
        document.body.innerHTML = printContents;
        window.print();
        document.body.innerHTML = originalContents;
        location.reload();
    }
</script>

<script>
    var enrollmentLocked = <?= $enrollment_locked ? 'true' : 'false' ?>;

    window.addEventListener("load", function () {
        var programSelect = document.getElementById("programSelect");
        var packageSelect = document.getElementById("packageSelect");
        var locked_warning = document.getElementById("locked_warning");



        if (enrollmentLocked === true) {
            // FIX: Don't disable the dropdowns, just show a warning.
            // programSelect.disabled = true;
            // packageSelect.disabled = true;
            locked_warning.innerHTML = "To avoid accidental errors, the Program and Package selections are locked."

        } else {
            programSelect.disabled = false;
            packageSelect.disabled = false;
            locked_warning.innerHTML = "";
        }
    });
</script>

<?php include "includes/footer.php"; ?>