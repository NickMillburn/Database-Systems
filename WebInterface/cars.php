<?php
// Page 2 of 3: add a vehicle to the Cars table, then move on to Title.
// newer PHP throws exceptions on SQL errors; turning that off lets the if/else checks below handle them
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli("localhost", "jetson", "orin", "DMV");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$error = "";
// owner info passed along from persons.php (optional, just forwarded to the title page)
$dl       = $_REQUEST["dl"] ?? "";
$dl_state = $_REQUEST["dl_state"] ?? "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $vin    = strtoupper(trim($_POST["vin"]));
    $make   = trim($_POST["make"]);
    $model  = trim($_POST["model"]);
    $year   = (int) $_POST["year"];
    $weight = (int) $_POST["weight"];

    if (strlen($vin) != 17) {
        $error = "VIN must be exactly 17 characters.";
    } else {
        $stmt = $conn->prepare("INSERT INTO Cars (VIN, Make, Model, Year, Weight) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssii", $vin, $make, $model, $year, $weight);
        if ($stmt->execute()) {
            header("Location: title.php?dl=" . urlencode($dl) . "&dl_state=" . urlencode($dl_state) . "&vin=" . urlencode($vin));
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
    <title>DMV - Register Car</title>
    <style>
        body { font-family: sans-serif; max-width: 500px; margin: 40px auto; }
        label { display: block; margin-top: 10px; }
        input { width: 100%; padding: 6px; box-sizing: border-box; }
        .error { color: red; }
        nav { margin-top: 20px; }
    </style>
</head>
<body>
    <h1>Step 2 of 3: Register Car</h1>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" action="cars.php">
        <input type="hidden" name="dl" value="<?php echo htmlspecialchars($dl); ?>">
        <input type="hidden" name="dl_state" value="<?php echo htmlspecialchars($dl_state); ?>">

        <label>VIN (17 characters)
            <input type="text" name="vin" maxlength="17" minlength="17" required
                   value="<?php echo htmlspecialchars($_POST["vin"] ?? ""); ?>">
        </label>
        <label>Make
            <input type="text" name="make" required
                   value="<?php echo htmlspecialchars($_POST["make"] ?? ""); ?>">
        </label>
        <label>Model
            <input type="text" name="model" required
                   value="<?php echo htmlspecialchars($_POST["model"] ?? ""); ?>">
        </label>
        <label>Year
            <input type="number" name="year" min="1900" max="2100" required
                   value="<?php echo htmlspecialchars($_POST["year"] ?? ""); ?>">
        </label>
        <label>Weight (lbs)
            <input type="number" name="weight" min="0" required
                   value="<?php echo htmlspecialchars($_POST["weight"] ?? ""); ?>">
        </label>
        <br>
        <input type="submit" value="Add Car and Continue">
    </form>

    <nav>
        <a href="persons.php">&larr; Previous: Register Owner</a> |
        <a href="title.php?dl=<?php echo urlencode($dl); ?>&dl_state=<?php echo urlencode($dl_state); ?>">Next: Create Title &rarr;</a>
    </nav>
</body>
</html>
