<?php
/**
 * SE Visual Studio - Contact Form Handler
 * Production-ready PHP backend for processing contact form submissions
 */

// Security: Prevent direct access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method Not Allowed');
}

// Security: Validate referer (optional, remove if causing issues)
// $allowedReferer = 'https://sevisualstudio.com';
// if (!isset($_SERVER['HTTP_REFERER']) || strpos($_SERVER['HTTP_REFERER'], $allowedReferer) !== 0) {
//     header('HTTP/1.1 403 Forbidden');
//     exit('Forbidden');
// }

// Configuration
$recipientEmail = 'info@sevisualstudio.com';
$subjectPrefix = '[SE Visual Studio Inquiry]';
$maxFileSize = 10 * 1024 * 1024; // 10MB
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'dwg', 'skp', 'max', 'blend'];
$uploadDir = __DIR__ . '/uploads/';

// Create uploads directory if it doesn't exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Response array
$response = [
    'success' => false,
    'message' => ''
];

// Sanitize and validate inputs
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Validate email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validate file upload
function validateFile($file, $allowedExtensions, $maxFileSize) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => true, 'message' => 'No file uploaded'];
        }
        return ['valid' => false, 'message' => 'File upload error: ' . $file['error']];
    }

    if ($file['size'] > $maxFileSize) {
        return ['valid' => false, 'message' => 'File size exceeds maximum limit of 10MB'];
    }

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        return ['valid' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExtensions)];
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg', 'image/png', 'application/pdf',
        'application/octet-stream', 'image/vnd.dwg'
    ];

    if (!in_array($mimeType, $allowedMimes) && !str_starts_with($mimeType, 'image/')) {
        return ['valid' => false, 'message' => 'Invalid file content type'];
    }

    return ['valid' => true, 'message' => 'File valid'];
}

try {
    // Get and sanitize form data
    $name = isset($_POST['name']) ? sanitizeInput($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitizeInput($_POST['email']) : '';
    $company = isset($_POST['company']) ? sanitizeInput($_POST['company']) : '';
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : '';
    $country = isset($_POST['country']) ? sanitizeInput($_POST['country']) : '';
    $projectType = isset($_POST['project_type']) ? sanitizeInput($_POST['project_type']) : '';
    $budget = isset($_POST['budget']) ? sanitizeInput($_POST['budget']) : '';
    $message = isset($_POST['message']) ? sanitizeInput($_POST['message']) : '';

    // Validation
    $errors = [];

    if (empty($name)) {
        $errors[] = 'Name is required';
    }

    if (empty($email) || !validateEmail($email)) {
        $errors[] = 'Valid email address is required';
    }

    if (empty($projectType)) {
        $errors[] = 'Project type is required';
    }

    if (empty($message)) {
        $errors[] = 'Project details are required';
    }

    // Honeypot check (anti-spam)
    if (!empty($_POST['website'])) {
        $errors[] = 'Spam detected';
    }

    if (!empty($errors)) {
        $response['message'] = implode(', ', $errors);
        echo json_encode($response);
        exit;
    }

    // Handle file upload
    $attachmentPath = null;
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $fileValidation = validateFile($_FILES['file'], $allowedExtensions, $maxFileSize);

        if (!$fileValidation['valid']) {
            $response['message'] = $fileValidation['message'];
            echo json_encode($response);
            exit;
        }

        // Generate safe filename
        $originalName = basename($_FILES['file']['name']);
        $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeFilename = uniqid('sevs_', true) . '.' . $fileExtension;
        $targetPath = $uploadDir . $safeFilename;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            $attachmentPath = $targetPath;
        } else {
            $response['message'] = 'Failed to save uploaded file';
            echo json_encode($response);
            exit;
        }
    }

    // Prepare email
    $emailSubject = $subjectPrefix . ' New Inquiry from ' . $name;

    $emailBody = "New Project Inquiry - SE Visual Studio\n";
    $emailBody .= str_repeat("=", 50) . "\n\n";
    $emailBody .= "Name: " . $name . "\n";
    $emailBody .= "Email: " . $email . "\n";
    $emailBody .= "Company: " . ($company ?: 'Not provided') . "\n";
    $emailBody .= "Phone: " . ($phone ?: 'Not provided') . "\n";
    $emailBody .= "Country: " . ($country ?: 'Not provided') . "\n";
    $emailBody .= "Project Type: " . $projectType . "\n";
    $emailBody .= "Budget Range: " . ($budget ?: 'Not specified') . "\n";
    $emailBody .= "\nProject Details:\n";
    $emailBody .= str_repeat("-", 30) . "\n";
    $emailBody .= $message . "\n";
    $emailBody .= str_repeat("-", 30) . "\n\n";
    $emailBody .= "Submitted: " . date('Y-m-d H:i:s') . "\n";
    $emailBody .= "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n";

    // Headers
    $headers = "From: " . $name . " <" . $email . ">\r\n";
    $headers .= "Reply-To: " . $email . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    // Send email with attachment if present
    if ($attachmentPath && file_exists($attachmentPath)) {
        // Send with attachment using multipart
        $boundary = md5(time());
        $headers .= "Content-Type: multipart/mixed; boundary="" . $boundary . ""\r\n";

        $body = "--" . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $body .= $emailBody . "\r\n";

        // Attachment
        $fileContent = file_get_contents($attachmentPath);
        $fileContent = chunk_split(base64_encode($fileContent));

        $body .= "--" . $boundary . "\r\n";
        $body .= "Content-Type: application/octet-stream; name="" . basename($originalName) . ""\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--" . $boundary . "--";

        $mailSent = mail($recipientEmail, $emailSubject, $body, $headers);
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mailSent = mail($recipientEmail, $emailSubject, $emailBody, $headers);
    }

    if ($mailSent) {
        $response['success'] = true;
        $response['message'] = 'Thank you! Your inquiry has been sent successfully. We will get back to you within 24 hours.';
    } else {
        $response['message'] = 'Sorry, there was an error sending your message. Please try again or contact us directly at ' . $recipientEmail;
    }

} catch (Exception $e) {
    $response['message'] = 'An error occurred: ' . $e->getMessage();
}

// Clean up uploaded file if it exists
if ($attachmentPath && file_exists($attachmentPath)) {
    // Optionally delete after sending: unlink($attachmentPath);
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>