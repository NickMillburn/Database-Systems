<?php
// Page 1 of 3: add a new owner to the Persons table, then move on to Cars.
// newer PHP throws exceptions on SQL errors; turning that off lets the if/else checks below handle them
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli("localhost", "jetson", "orin", "DMV");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $license = trim($_POST["license"]);
    $state   = strtoupper(trim($_POST["state"]));
    $last    = trim($_POST["last"]);
    $first   = trim($_POST["first"]);
    $phone   = trim($_POST["phone"]);
    $email   = trim($_POST["email"]);

    if (strlen($license) != 9) {
        $error = "License must be exactly 9 characters.";
    } elseif (strlen($state) != 2) {
        $error = "State must be exactly 2 characters.";
    } elseif (strlen($phone) != 10) {
        $error = "Phone must be exactly 10 digits.";
    } else {
        $stmt = $conn->prepare("INSERT INTO Persons (License, State, Last, First, Phone, Email) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $license, $state, $last, $first, $phone, $email);
        if ($stmt->execute()) {
            // carry the new license forward so the Title page can be prefilled
            header("Location: cars.php?dl=" . urlencode($license) . "&dl_state=" . urlencode($state));
            exit();
        }
        $error = "Insert failed: " . $stmt->error;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>DMV - Register Owner</title>
    <style>
        body { font-family: sans-serif; max-width: 500px; margin: 40px auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 6px; box-sizing: border-box; }
        .error { color: red; }
        nav { margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Step 1 of 3: Register Owner</h1>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" action="persons.php">
        <label>License (9 characters)
            <input type="text" name="license" maxlength="9" minlength="9" required
                   value="<?php echo htmlspecialchars($_POST["license"] ?? ""); ?>">
        </label>
        <label>State (2 characters)
            <input type="text" name="state" maxlength="2" minlength="2" required
                   value="<?php echo htmlspecialchars($_POST["state"] ?? ""); ?>">
        </label>
        <label>Last Name
            <input type="text" name="last" required
                   value="<?php echo htmlspecialchars($_POST["last"] ?? ""); ?>">
        </label>
        <label>First Name
            <input type="text" name="first" required
                   value="<?php echo htmlspecialchars($_POST["first"] ?? ""); ?>">
        </label>
        <label>Phone (10 digits)
            <input type="text" name="phone" maxlength="10" minlength="10" pattern="[0-9]{10}" required
                   value="<?php echo htmlspecialchars($_POST["phone"] ?? ""); ?>">
        </label>
        <label>Email
            <input type="email" name="email" required
                   value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>">
        </label>
        <br>
        <input type="submit" value="Add Owner and Continue">
    </form>

    <nav>
        <a href="cars.php">Next: Register Car &rarr;</a>
    </nav>
</body>
</html>
