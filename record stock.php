<?php
include 'db.php';
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}

$message = "";
$hasNotes = !empty($GLOBALS['clinic_stock_has_notes']);
$hasOtherDetails = !empty($GLOBALS['clinic_stock_has_other_details']);
$hasImagePath = !empty($GLOBALS['clinic_stock_has_image_path']);
$hasUpdatedAt = !empty($GLOBALS['clinic_stock_has_updated_at']);
$hasUpdatedBy = !empty($GLOBALS['clinic_stock_has_updated_by']);
$hasDateReceived = !empty($GLOBALS['clinic_stock_has_date_received']);
$expiryCutoff = date('Y-m-d', strtotime('+30 days'));
$formCategory = trim($_POST['category'] ?? '');
$formOtherDetails = trim($_POST['other_details'] ?? '');

function clinic_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    $bind = [$types];
    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function clinic_param_types(array $params): string
{
    $types = '';
    foreach ($params as $value) {
        $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
    }
    return $types;
}

$suppliers = [];
$supplierResult = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
if ($supplierResult) {
    while ($row = $supplierResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

$recentStock = [];
$recentStockSelect = $hasDateReceived
    ? "s.name, s.batch_number, s.quantity, s.created_at, s.date_received, s.updated_at, s.updated_by, sp.name AS supplier_name"
    : "s.name, s.batch_number, s.quantity, s.created_at, NULL AS date_received, s.updated_at, s.updated_by, sp.name AS supplier_name";
$recentStockResult = $conn->query("
    SELECT {$recentStockSelect}
    FROM stock s
    LEFT JOIN suppliers sp ON s.supplier_id = sp.id
    ORDER BY s.id DESC
    LIMIT 5
");
if ($recentStockResult) {
    while ($row = $recentStockResult->fetch_assoc()) {
        $recentStock[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_supplier') {
    $supplierName = trim($_POST['supplier_name'] ?? '');

    if ($supplierName === '') {
        $message = "Please enter a supplier name.";
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM suppliers WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $supplierName);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = "Supplier already exists.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO suppliers (name) VALUES (?)");
            $stmt->bind_param("s", $supplierName);
            if ($stmt->execute()) {
                $message = "Supplier added successfully.";
                $supplierResult = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
                $suppliers = [];
                if ($supplierResult) {
                    while ($row = $supplierResult->fetch_assoc()) {
                        $suppliers[] = $row;
                    }
                }
            } else {
                $message = "Could not add supplier. Please try again.";
            }
        }
        $stmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $batch = trim($_POST['batch'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $unitPrice = trim($_POST['unit_price'] ?? '');
    $supplierName = trim($_POST['supplier'] ?? '');
    $expiry = trim($_POST['expiry_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $otherDetails = $formOtherDetails;
    if ($category !== 'Other') {
        $otherDetails = '';
    }

    $imagePath = null;

    if (isset($_FILES['image']) && !empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = "Could not upload the image. Please try again.";
        } elseif (!is_uploaded_file($_FILES['image']['tmp_name']) || @getimagesize($_FILES['image']['tmp_name']) === false) {
            $message = "Please upload a valid image file.";
        } else {
            $extension = strtolower(pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($extension, $allowedExtensions, true)) {
                $message = "Please upload a JPG, PNG, GIF, or WEBP image.";
            } else {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stock';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $message = "Could not prepare the image folder.";
                } else {
                    $safeName = uniqid('stock_', true) . '.' . $extension;
                    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                        $imagePath = 'uploads/stock/' . $safeName;
                    } else {
                        $message = "Could not save the uploaded image.";
                    }
                }
            }
        }
    }

    if ($message === '') {
        if ($name === '' || $quantity <= 0) {
            $message = "Please provide a medicine name and a quantity above zero.";
        } elseif ($category === '') {
            $message = "Please select a category.";
        } elseif ($category === 'Other' && $otherDetails === '') {
            $message = "Please explain what the other item is.";
        } elseif ($expiry === '') {
            $message = "Please choose an expiry date.";
        } elseif (strtotime($expiry) === false) {
            $message = "Please choose a valid expiry date.";
        } elseif (strtotime($expiry) <= strtotime($expiryCutoff)) {
            $message = "This item is expired or too close to expiry. Please do not record it.";
        } else {
            $supplierId = null;
            if ($supplierName !== '') {
                $stmt = $conn->prepare("SELECT id FROM suppliers WHERE name = ? LIMIT 1");
                $stmt->bind_param("s", $supplierName);
                $stmt->execute();
                $stmt->bind_result($supplierId);
                $stmt->fetch();
                $stmt->close();

                if (!$supplierId) {
                    $message = "Supplier not found. Please add it manually first.";
                }
            }

            if ($message === '') {
                $conn->begin_transaction();
                try {
                    $stockSelect = $hasImagePath ? "id, quantity, image_path" : "id, quantity, NULL AS image_path";
                    $stmt = $conn->prepare("SELECT {$stockSelect} FROM stock WHERE name = ? AND batch_number = ? LIMIT 1");
                    $stmt->bind_param("ss", $name, $batch);
                    $stmt->execute();
                    $stmt->bind_result($existingId, $existingQty, $existingImagePath);
                    $stmt->fetch();
                    $stmt->close();

                    if ($existingId) {
                        $newQty = (int)$existingQty + $quantity;

                        $setParts = [
                            "quantity = ?",
                            "category = ?",
                            "unit_price = ?",
                        ];
                        $params = [$newQty, $category, $unitPrice];

                        if ($supplierId !== null) {
                            $setParts[] = "supplier_id = ?";
                            $params[] = (int)$supplierId;
                        }

                        $setParts[] = "expiry_date = ?";
                        $params[] = $expiry;

                        if ($hasNotes) {
                            $setParts[] = "notes = ?";
                            $params[] = $notes;
                        }

                        if ($hasOtherDetails) {
                            $setParts[] = "other_details = ?";
                            $params[] = $category === 'Other' ? $otherDetails : null;
                        }

                        if ($imagePath !== null && $hasImagePath) {
                            $setParts[] = "image_path = ?";
                            $params[] = $imagePath;
                        }

                        if ($hasUpdatedAt) {
                            $setParts[] = "updated_at = NOW()";
                        }
                        if ($hasUpdatedBy) {
                            $setParts[] = "updated_by = ?";
                            $params[] = $_SESSION['username'];
                        }

                        $sql = "UPDATE stock SET " . implode(", ", $setParts) . " WHERE id = ?";
                        $params[] = (int)$existingId;
                        $types = clinic_param_types($params);
                        $stmt = $conn->prepare($sql);
                        clinic_bind_params($stmt, $types, $params);
                        $stmt->execute();
                        $stmt->close();
                        $message = "Stock updated. Quantity increased.";
                    } else {
                        $columns = ["name", "category", "batch_number", "quantity", "unit_price"];
                        $values = [$name, $category, $batch, $quantity, $unitPrice];

                        if ($supplierId !== null) {
                            $columns[] = "supplier_id";
                            $values[] = (int)$supplierId;
                        }

                        if ($hasDateReceived) {
                            $columns[] = "date_received";
                            $values[] = date('Y-m-d');
                        }

                        $columns[] = "expiry_date";
                        $values[] = $expiry;

                        if ($hasNotes) {
                            $columns[] = "notes";
                            $values[] = $notes;
                        }
                        if ($hasOtherDetails) {
                            $columns[] = "other_details";
                            $values[] = $category === 'Other' ? $otherDetails : null;
                        }
                        if ($hasUpdatedBy) {
                            $columns[] = "updated_by";
                            $values[] = $_SESSION['username'];
                        }

                        if ($imagePath !== null && $hasImagePath) {
                            $columns[] = "image_path";
                            $values[] = $imagePath;
                        }

                        if ($hasUpdatedAt) {
                            $columns[] = "created_at";
                            $columns[] = "updated_at";
                        }

                        $sql = "INSERT INTO stock (" . implode(", ", $columns) . ") VALUES (";
                        $sql .= implode(", ", array_fill(0, count($values), "?"));
                        if ($hasUpdatedAt) {
                            $sql .= ", NOW(), NOW())";
                        } else {
                            $sql .= ")";
                        }

                        $types = clinic_param_types($values);
                        $stmt = $conn->prepare($sql);
                        clinic_bind_params($stmt, $types, $values);
                        $stmt->execute();
                        $stmt->close();
                        $message = "New stock item recorded.";
                    }

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Could not save stock entry. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Record Stock | City Clinic</title>
<link rel="stylesheet" href="ui.css">
</head>

<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-badge"></span>
      City Clinic
    </div>
    <div class="role-pill">Role: <?= ucfirst($_SESSION['role']) ?></div>

    <nav class="nav">
      <a class="nav-item" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 12l9-9 9 9"></path>
          <path d="M9 21V9h6v12"></path>
        </svg>
        Dashboard
      </a>

      <?php if($_SESSION['role'] != 'pharmacist'): ?>
        <a class="nav-item active" href="record stock.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 5v14"></path>
            <path d="M5 12h14"></path>
          </svg>
          Record Stock
        </a>
      <?php endif; ?>

      <a class="nav-item" href="view stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 4h18v6H3z"></path>
          <path d="M3 14h18v6H3z"></path>
        </svg>
        View Stock
      </a>

      <a class="nav-item" href="order stock.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 6h15l-2 9H8L6 6z"></path>
          <path d="M6 6l-2-3H1"></path>
          <circle cx="9" cy="20" r="1.5"></circle>
          <circle cx="18" cy="20" r="1.5"></circle>
        </svg>
        Order Stock
      </a>

      <a class="nav-item" href="supplier.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 7h16"></path>
          <path d="M6 7v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"></path>
          <path d="M9 11h6"></path>
        </svg>
        Suppliers
      </a>

      <a class="nav-item" href="reports.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 19h16"></path>
          <path d="M6 16V8"></path>
          <path d="M12 16V5"></path>
          <path d="M18 16v-6"></path>
        </svg>
        Reports
      </a>

      <?php if($_SESSION['role'] == 'admin'): ?>
        <a class="nav-item" href="user management.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M16 11a4 4 0 1 0-8 0"></path>
            <path d="M12 15c-4 0-7 2-7 4v2h14v-2c0-2-3-4-7-4z"></path>
          </svg>
          User Management
        </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <a class="logout" href="logout.php" onclick="return confirm('Do you want to sign out now?')">Logout</a>
    </div>
  </aside>

  <main class="main">
    <header class="page-header">
      <div>
        <h1>Record Stock</h1>
        <p>Log incoming inventory with clear, structured fields.</p>
      </div>
      <div class="header-actions">
        <div class="chip">New intake</div>
        <div class="chip"><?= date("F j, Y") ?></div>
      </div>
    </header>

    <section class="panel">
      <h3>New Stock Entry</h3>
      <?php if ($message): ?>
        <div class="mini-row" style="margin-bottom:14px;">
          <strong><?= htmlspecialchars($message) ?></strong>
        </div>
      <?php endif; ?>
      <form class="form" action="record stock.php" method="post" enctype="multipart/form-data">
        <div class="field">
          <label>Medicine Name</label>
          <input type="text" name="name" placeholder="e.g. Amoxicillin 500mg" required>
        </div>
        <div class="field">
          <label>Category</label>
          <select name="category" id="categorySelect">
            <option value="">Select category</option>
            <?php foreach (clinic_stock_categories() as $categoryOption): ?>
              <option value="<?= htmlspecialchars($categoryOption) ?>" <?= $formCategory === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" id="otherDetailsField" style="display:none;">
          <label>Explain Other Category</label>
          <textarea rows="3" name="other_details" id="otherDetails" placeholder="Describe the item, for example wound dressing, lab reagent, or clinic stationery."><?= htmlspecialchars($formOtherDetails) ?></textarea>
          <div class="form-helper">This is required only when you choose Other.</div>
        </div>
        <div class="field">
          <label>Batch Number</label>
          <input type="text" name="batch" placeholder="Batch ID">
        </div>
        <div class="field">
          <label>Quantity</label>
          <input type="number" name="quantity" placeholder="0" min="1" required>
        </div>
        <div class="field">
          <label>Unit Price</label>
          <input type="text" name="unit_price" placeholder="$0.00">
        </div>
        <div class="field">
          <label>Supplier</label>
          <select name="supplier" required>
            <option value="">Select supplier</option>
            <?php foreach ($suppliers as $supplier): ?>
              <option value="<?= htmlspecialchars($supplier['name']) ?>"><?= htmlspecialchars($supplier['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-helper">Choose an existing supplier before saving stock.</div>
        </div>
        <div class="field">
          <label>Expiry Date</label>
          <input type="date" name="expiry_date" required min="<?= date('Y-m-d', strtotime('+31 days')) ?>">
          <div class="form-helper">Only items with an expiry date more than 30 days away can be recorded.</div>
        </div>
        <div class="field">
          <label>Item Image (optional)</label>
          <input type="file" name="image" accept="image/*">
        </div>
        <div class="field">
          <label>Notes</label>
          <textarea rows="3" name="notes" placeholder="Special handling or storage info"></textarea>
        </div>
        <div class="form-actions">
          <button class="button ghost" type="reset">Clear</button>
          <button class="button primary" type="submit">Save Entry</button>
        </div>
      </form>
    </section>

    <section class="panel" style="margin-top:22px;">
      <div class="panel-header">
        <div>
          <h3>Recent Stock Saves</h3>
          <p>Latest stock entries and updates pulled directly from the database.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Medicine</th>
              <th>Batch</th>
              <th>Qty</th>
              <th>Supplier</th>
              <th>Last Updated</th>
              <th>Recorded</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($recentStock) === 0): ?>
              <tr>
                <td colspan="5">No stock entries found yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentStock as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td><?= htmlspecialchars($row['batch_number'] ?? '-') ?></td>
                  <td><?= (int)$row['quantity'] ?></td>
                  <td><?= htmlspecialchars($row['supplier_name'] ?? '-') ?></td>
                  <td>
                    <?= !empty($row['updated_at']) ? date("M j, Y g:i A", strtotime($row['updated_at'])) : '-' ?><br>
                    <small style="color:var(--muted);"><?= htmlspecialchars($row['updated_by'] ?? '-') ?></small>
                  </td>
                  <td><?= !empty($row['date_received']) ? date("M j, Y", strtotime($row['date_received'])) : (!empty($row['created_at']) ? date("M j, Y", strtotime($row['created_at'])) : '-') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel" style="margin-top:22px;">
      <h3>Add Supplier Manually</h3>
      <form class="form" action="record stock.php" method="post">
        <input type="hidden" name="action" value="add_supplier">
        <div class="field">
          <label>Supplier Name</label>
          <input type="text" name="supplier_name" placeholder="Enter supplier name">
        </div>
        <div class="form-actions">
          <button class="button primary" type="submit">Add Supplier</button>
        </div>
      </form>
    </section>
  </main>
</div>
<script>
(function () {
  const categorySelect = document.getElementById('categorySelect');
  const otherDetailsField = document.getElementById('otherDetailsField');
  const otherDetails = document.getElementById('otherDetails');

  if (!categorySelect || !otherDetailsField || !otherDetails) {
    return;
  }

  function syncOtherDetails() {
    const isOther = categorySelect.value === 'Other';
    otherDetailsField.style.display = isOther ? 'block' : 'none';
    otherDetails.required = isOther;
    if (!isOther) {
      otherDetails.value = '';
    }
  }

  categorySelect.addEventListener('change', syncOtherDetails);
  syncOtherDetails();
})();
</script>
</body>
</html>
