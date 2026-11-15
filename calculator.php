<?php
/**
 * ============================================================
 *  Robust Calculator - Error Handling & Debugging
 * ============================================================
 *  Name       : <HỌ VÀ TÊN CỦA BẠN>
 *  Student ID : <MÃ SỐ SINH VIÊN>
 *  Date       : 2026-11-14
 *
 *  Description: Single-file PHP calculator with custom exceptions,
 *  file-based error logging and a session-based history.
 * ============================================================
 */

// session_start() must run before ANY output.
session_start();

// Development: report everything, but never show raw PHP errors to the user.
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Turn every PHP warning/notice into an exception so it is caught and logged
// by the generic catch block instead of leaking to the browser.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* ------------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------------ */
const LOG_FILE      = __DIR__ . '/calculator_errors.log';
const HISTORY_LIMIT = 50;
const OPERATORS     = ['+', '-', '*', '/', '%', '**'];

/* ------------------------------------------------------------------
 * Custom exception classes
 * ------------------------------------------------------------------ */
class DivisionByZeroException extends Exception
{
    public function __construct(string $message = 'Division by zero is not allowed.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class InvalidOperatorException extends Exception
{
    public function __construct(string $message = 'The operator is not supported.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class InvalidInputException extends Exception
{
    public function __construct(string $message = 'The input is not a valid number.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
// Overflow uses PHP's built-in SPL \OverflowException.

/* ------------------------------------------------------------------
 * Helper functions
 * ------------------------------------------------------------------ */

/** Escape text for safe HTML output. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a POST field as a trimmed string ('' if missing or an array). */
function postValue(string $name): string
{
    $value = filter_input(INPUT_POST, $name, FILTER_UNSAFE_RAW);
    return is_string($value) ? trim($value) : '';
}

/** Client IP address (validated). */
function clientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** Create a .htaccess that blocks web access to *.log files (only if none exists). */
function protectLogFile(): void
{
    $htaccess = __DIR__ . '/.htaccess';
    if (!is_file($htaccess) && is_writable(__DIR__)) {
        $rules = "# Block direct web access to log files\n"
               . "<FilesMatch \"\\.log\$\">\n"
               . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
               . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
               . "</FilesMatch>\n";
        file_put_contents($htaccess, $rules);
    }
}

/**
 * Append an error line to calculator_errors.log
 * Format: [YYYY-MM-DD HH:MM:SS] ExceptionType: message | Input: "a op b" | IP: user_ip
 */
function logError(Throwable $e, string $a, string $op, string $b): void
{
    $clean = function (string $s): string {
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s); // prevent log injection
        return substr($s, 0, 100);
    };

    $line = sprintf(
        '[%s] %s: %s | Input: "%s %s %s" | IP: %s',
        date('Y-m-d H:i:s'),
        get_class($e),
        $clean($e->getMessage()),
        $clean($a),
        $clean($op),
        $clean($b),
        clientIp()
    ) . PHP_EOL;

    if (is_writable(__DIR__) || is_writable(LOG_FILE)) {
        file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}

/** Validate and convert a raw string to int|float. */
function parseNumber(string $raw, string $label)
{
    if ($raw === '' || !is_numeric($raw)) {
        throw new InvalidInputException(
            "The $label is not a valid number" . ($raw === '' ? ' (it is empty).' : ': "' . mb_substr($raw, 0, 30) . '".')
        );
    }

    // Whole numbers that fit in PHP's int range stay as int.
    $int = filter_var($raw, FILTER_VALIDATE_INT);
    if ($int !== false) {
        return $int;
    }

    $float = (float) $raw;
    if (is_infinite($float) || is_nan($float)) {
        throw new InvalidInputException("The $label is too large to be processed.");
    }
    return $float;
}

/** Format a number for display. */
function formatNumber($n): string
{
    if (is_int($n)) {
        return (string) $n;
    }
    if (floor($n) == $n && abs($n) < 1e15) {
        return (string) (int) $n;                       // whole value -> show as integer
    }
    if (abs($n) >= 1e15) {
        return sprintf('%.6E', $n);                     // very large -> scientific
    }
    return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.'); // decimals, trailing zeros trimmed
}

/** Perform the calculation (validate early, fail fast). */
function calculate($a, string $op, $b)
{
    if (!in_array($op, OPERATORS, true)) {
        throw new InvalidOperatorException(
            'The operator "' . mb_substr($op, 0, 10) . '" is not supported. Use one of: + - * / % **'
        );
    }

    switch ($op) {
        case '+':
            $result = $a + $b;
            break;
        case '-':
            $result = $a - $b;
            break;
        case '*':
            $result = $a * $b;
            break;
        case '/':
            if ($b == 0) {
                throw new DivisionByZeroException('You cannot divide by zero.');
            }
            $result = $a / $b;
            break;
        case '%':
            if ($b == 0) {
                throw new DivisionByZeroException('You cannot calculate a modulo by zero.');
            }
            $result = (is_int($a) && is_int($b)) ? $a % $b : fmod((float) $a, (float) $b);
            break;
        case '**':
            if ($a == 0 && $b < 0) {
                throw new DivisionByZeroException('Zero cannot be raised to a negative power (it means dividing by zero).');
            }
            $result = $a ** $b;
            break;
        default:
            throw new InvalidOperatorException('Unsupported operator.');
    }

    // Overflow / undefined detection
    if (is_float($result)) {
        if (is_nan($result)) {
            throw new OverflowException('The result is not a real number.');
        }
        if (is_infinite($result)) {
            throw new OverflowException('The result is too large for PHP to represent (overflow).');
        }
    }

    return $result;
}

/* ------------------------------------------------------------------
 * Request handling
 * ------------------------------------------------------------------ */
protectLogFile();

if (!isset($_SESSION['history']) || !is_array($_SESSION['history'])) {
    $_SESSION['history'] = [];
}

$num1 = $operator = $num2 = '';
$msgType = null;   // 'success' | 'error' | 'warning'
$msgTitle = '';
$msgText = '';
$msgHint = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Sticky form values
    $num1     = postValue('num1');
    $operator = postValue('operator');
    $num2     = postValue('num2');
    $action   = postValue('action');

    if ($action === 'clear') {
        $_SESSION['history'] = [];
        $msgType  = 'warning';
        $msgTitle = 'History cleared';
        $msgText  = 'Your calculation history has been removed.';
    } elseif ($action === 'calculate') {
        try {
            $a = parseNumber($num1, 'first number');
            $b = parseNumber($num2, 'second number');

            $result = calculate($a, $operator, $b);

            $resultText = ($operator === '/') ? number_format((float) $result, 4, '.', '') : formatNumber($result);
            $expression = formatNumber($a) . ' ' . $operator . ' ' . formatNumber($b);

            $msgType  = 'success';
            $msgTitle = 'Result';
            $msgText  = $expression . ' = ' . $resultText;

            array_unshift($_SESSION['history'], [
                'expression' => $expression,
                'result'     => $resultText,
                'timestamp'  => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['history'] = array_slice($_SESSION['history'], 0, HISTORY_LIMIT);

        } catch (DivisionByZeroException $e) {
            logError($e, $num1, $operator, $num2);
            $msgType  = 'error';
            $msgTitle = 'Division by zero';
            $msgText  = $e->getMessage();
            $msgHint  = 'Change the second number to something other than 0.';
        } catch (InvalidOperatorException $e) {
            logError($e, $num1, $operator, $num2);
            $msgType  = 'error';
            $msgTitle = 'Invalid operator';
            $msgText  = $e->getMessage();
            $msgHint  = 'Choose an operator from the dropdown list.';
        } catch (InvalidInputException $e) {
            logError($e, $num1, $operator, $num2);
            $msgType  = 'error';
            $msgTitle = 'Invalid input';
            $msgText  = $e->getMessage();
            $msgHint  = 'Enter numbers only, e.g. 12, -3.5 or 2e3.';
        } catch (OverflowException $e) {
            logError($e, $num1, $operator, $num2);
            $msgType  = 'error';
            $msgTitle = 'Overflow';
            $msgText  = $e->getMessage();
            $msgHint  = 'Try smaller numbers or a smaller exponent.';
        } catch (Throwable $e) {
            logError($e, $num1, $operator, $num2);
            $msgType  = 'error';
            $msgTitle = 'Unexpected error';
            $msgText  = 'Something went wrong while calculating. The problem has been logged.';
            $msgHint  = 'Please try again with different values.';
        }
    } else {
        $msgType  = 'warning';
        $msgTitle = 'Unknown action';
        $msgText  = 'Please use the "Calculate" or "Clear History" button.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Robust Calculator</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Segoe UI, Arial, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 20px;
            color: #222;
        }
        .container {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.1);
        }
        h1 { margin-top: 0; font-size: 1.6rem; text-align: center; }
        h2 { font-size: 1.2rem; margin-top: 28px; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; }
        .field { flex: 1 1 140px; display: flex; flex-direction: column; }
        label { font-weight: 600; margin-bottom: 4px; font-size: .9rem; }
        input[type=text], select {
            padding: 10px;
            border: 1px solid #bbb;
            border-radius: 6px;
            font-size: 1rem;
            width: 100%;
        }
        input:focus, select:focus { outline: 2px solid #4a90e2; border-color: #4a90e2; }
        .buttons { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            color: #fff;
        }
        .btn-calc  { background: #2e7d32; }
        .btn-clear { background: #c77700; }
        button:hover { opacity: .9; }

        .box { margin-top: 18px; padding: 14px 16px; border-radius: 6px; border-left: 6px solid; }
        .box .title { font-weight: 700; margin-bottom: 4px; }
        .box .hint  { margin-top: 6px; font-size: .9rem; font-style: italic; }
        .success { background: #e8f5e9; border-color: #2e7d32; color: #1b5e20; }
        .error   { background: #fdecea; border-color: #c62828; color: #8e1b1b; }
        .warning { background: #fff8e1; border-color: #f9a825; color: #7a5b00; }
        .result  { font-size: 1.3rem; font-weight: 600; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #ddd; text-align: left; font-size: .95rem; }
        th { background: #f5f5f5; }
        .empty { color: #777; font-style: italic; }

        @media (max-width: 480px) {
            .container { padding: 16px; }
            button { flex: 1 1 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Robust Calculator</h1>

    <form method="post" action="calculator.php" novalidate>
        <div class="row">
            <div class="field">
                <label for="num1">First number</label>
                <input type="text" id="num1" name="num1" inputmode="decimal"
                       value="<?= h($num1) ?>" placeholder="e.g. 10" autocomplete="off">
            </div>
            <div class="field">
                <label for="operator">Operator</label>
                <select id="operator" name="operator">
                    <?php foreach (OPERATORS as $op): ?>
                        <option value="<?= h($op) ?>"<?= ($operator === $op) ? ' selected' : '' ?>><?= h($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="num2">Second number</label>
                <input type="text" id="num2" name="num2" inputmode="decimal"
                       value="<?= h($num2) ?>" placeholder="e.g. 3" autocomplete="off">
            </div>
        </div>

        <div class="buttons">
            <button type="submit" name="action" value="calculate" class="btn-calc">Calculate</button>
            <button type="submit" name="action" value="clear" class="btn-clear">Clear History</button>
        </div>
    </form>

    <?php if ($msgType !== null): ?>
        <div class="box <?= h($msgType) ?>" role="<?= $msgType === 'error' ? 'alert' : 'status' ?>">
            <div class="title"><?= h($msgTitle) ?></div>
            <div<?= $msgType === 'success' ? ' class="result"' : '' ?>><?= h($msgText) ?></div>
            <?php if ($msgHint !== ''): ?>
                <div class="hint">How to fix: <?= h($msgHint) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Calculation History</h2>
    <?php if (empty($_SESSION['history'])): ?>
        <p class="empty">No calculations yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>#</th><th>Expression</th><th>Result</th><th>Time</th></tr>
                </thead>
                <tbody>
                    <?php $count = count($_SESSION['history']); ?>
                    <?php foreach ($_SESSION['history'] as $i => $record): ?>
                        <tr>
                            <td><?= $count - $i ?></td>
                            <td><?= h($record['expression'] ?? '') ?></td>
                            <td><?= h($record['result'] ?? '') ?></td>
                            <td><?= h($record['timestamp'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
