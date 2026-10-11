<?php
// Page 3 of 3: link an owner to a car in the Title table.
// newer PHP throws exceptions on SQL errors; turning that off lets the if/else checks below handle them
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli("localhost", "jetson", "orin", "DMV");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error   = "";
$success = "";
// prefill from the previous pages when available
$dl       = $_REQUEST["dl"] ?? "";
$dl_state = $_REQUEST["dl_state"] ?? "";
$vin      = $_REQUEST["vin"] ?? "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $dl       = trim($_POST["dl"]);
    $dl_state = strtoupper(trim($_POST["dl_state"]));
    $vin      = strtoupper(trim($_POST["vin"]));
    $state    = strtoupper(trim($_POST["state"]));
    $month    = (int) $_POST["month"];
    $year     = (int) $_POST["year"];

    if (strlen($dl) != 9) {
        $error = "DL must be exactly 9 characters.";
    } elseif (strlen($dl_state) != 2) {
        $error = "DL State must be exactly 2 characters.";
    } elseif (strlen($vin) != 17) {
        $error = "VIN must be exactly 17 characters.";
    } elseif (strlen($state) != 2) {
        $error = "State must be exactly 2 characters.";
    } elseif ($month < 1 || $month > 12) {
        $error = "Month must be between 1 and 12.";
    } else {
        // TitleID is auto increment, so it is left out of the insert
        $stmt = $conn->prepare("INSERT INTO Title (DL, DL_State, VIN, State, Month, Year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssii", $dl, $dl_state, $vin, $state, $month, $year);
        if ($stmt->execute()) {
            $success = "Title #" . $stmt->insert_id . " created successfully. Car " . $vin . " is now registered to license " . $dl . " (" . $dl_state . ").";
            $dl = $dl_state = $vin = "";
        } else {
            $error = "Insert failed: " . $stmt->error;
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>DMV - Create Title</title>
    <style>
        body { font-family: sans-serif; max-width: 500px; margin: 40px auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 6px; box-sizing: border-box; }
        .error { color: red; }
        .success { color: green; font-weight: bold; }
        nav { margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Step 3 of 3: Create Title</h1>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p class="success"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>

    <form method="post" action="title.php">
        <label>Owner License (DL, 9 characters)
            <input type="text" name="dl" maxlength="9" minlength="9" required
                   value="<?php echo htmlspecialchars($dl); ?>">
        </label>
        <label>Owner License State (DL_State, 2 characters)
            <input type="text" name="dl_state" maxlength="2" minlength="2" required
                   value="<?php echo htmlspecialchars($dl_state); ?>">
        </label>
        <label>VIN (17 characters)
            <input type="text" name="vin" maxlength="17" minlength="17" required
                   value="<?php echo htmlspecialchars($vin); ?>">
        </label>
        <label>Title State (2 characters)
            <input type="text" name="state" maxlength="2" minlength="2" required
                   value="<?php echo htmlspecialchars($_POST["state"] ?? ""); ?>">
        </label>
        <label>Month (1-12)
            <input type="number" name="month" min="1" max="12" required
                   value="<?php echo htmlspecialchars($_POST["month"] ?? ""); ?>">
        </label>
        <label>Year
            <input type="number" name="year" min="1900" max="2100" required
                   value="<?php echo htmlspecialchars($_POST["year"] ?? ""); ?>">
        </label>
        <br>
        <input type="submit" value="Create Title">
    </form>

    <nav>
        <a href="cars.php">&larr; Previous: Register Car</a> |
        <a href="persons.php">Start Over: Register Owner</a>
    </nav>
</body>
</html>
