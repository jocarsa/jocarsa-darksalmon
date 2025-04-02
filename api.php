<?php

require_once __DIR__ . '/db_init.php';
$pdo = getPDOConnection(); // ensures DB is created

// If not called directly, do nothing (just load the functions)
if (basename(__FILE__) !== basename($_SERVER['PHP_SELF'])) {
    return;
}

/**
 * ---------------------------------------------------------
 * If we're here, then user directly requested api.php in the
 * browser or via fetch() requests. So let's output JSON.
 * ---------------------------------------------------------
 */
header('Content-Type: application/json; charset=utf-8');

// We'll use session for worker logTime and for potential role checks
session_start();

// 1. Parse request
$endpoint = $_GET['endpoint'] ?? null;
$method   = $_SERVER['REQUEST_METHOD'];
$id       = isset($_GET['id']) ? intval($_GET['id']) : null;

// For POST/PUT/DELETE, parse JSON from body
$inputData = [];
if (in_array($method, ['POST','PUT','DELETE'])) {
    $json = file_get_contents("php://input");
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
    }
}

// 2. Route by endpoint
switch ($endpoint) {
    case 'menu':
        // Now admin can see "Empleados" plus these others
        echo json_encode([
            ["etiqueta" => "Empleados"],
            ["etiqueta" => "Asistencia"],
            ["etiqueta" => "Vacaciones"],
            ["etiqueta" => "Contratos"],
            ["etiqueta" => "Incidencias"]
            // Could also add "Usuarios" if you want a separate CRUD
        ]);
        break;

    case 'Empleados':
        handleEmpleados($pdo, $method, $id, $inputData);
        break;

    case 'Asistencia':
    case 'Vacaciones':
    case 'Contratos':
    case 'Incidencias':
        crudGenerico($pdo, $endpoint, $method, $id, $inputData);
        break;

    case 'logTime':
        handleLogTime($pdo, $method, $inputData);
        break;

    default:
        echo json_encode(["error" => "Endpoint not valid"]);
        break;
}

exit;

/** -----------------------------------------
 *  handleEmpleados
 *  - Enhanced to also store/edit user info
 * -----------------------------------------
 */
function handleEmpleados(PDO $pdo, $method, $id, $inputData) {
    switch ($method) {
        case 'GET':
            // Build metadata with user fields included
            $stmt = $pdo->query("SELECT id, name FROM tipos_contrato");
            $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $optionsTipo = [];
            foreach ($tipos as $t) {
                $optionsTipo[] = [ "value" => $t['id'], "label" => $t['name'] ];
            }

            // For 'rol' we can do a small set
            $optionsRol = [
                ["value" => "admin",  "label" => "Admin"],
                ["value" => "worker", "label" => "Trabajador"]
            ];

            $meta = [
                "fields" => [
                    [ "name" => "id",               "label" => "ID",              "type" => "number", "readonly" => true ],
                    [ "name" => "nombre",           "label" => "Nombre",          "type" => "text" ],
                    [ "name" => "apellido",         "label" => "Apellido",        "type" => "text" ],
                    [ "name" => "departamento",     "label" => "Departamento",    "type" => "text" ],
                    [
                        "name" => "tipo_contrato_id",
                        "label" => "Tipo Contrato",
                        "type" => "select",
                        "options" => $optionsTipo
                    ],
                    // New fields to manage the user account
                    [ "name" => "username",   "label" => "Usuario",     "type" => "text" ],
                    [ "name" => "password",   "label" => "Contraseña",  "type" => "text" ],
                    [
                        "name" => "rol",
                        "label" => "Rol",
                        "type" => "select",
                        "options" => $optionsRol
                    ],
                ]
            ];

            // Data
            if ($id) {
                $sql = "
                    SELECT e.id, e.nombre, e.apellido, e.departamento, e.tipo_contrato_id,
                           tc.name AS tipo_contrato_desc,
                           u.username, u.password, u.rol
                    FROM empleados e
                    LEFT JOIN tipos_contrato tc ON e.tipo_contrato_id = tc.id
                    LEFT JOIN usuarios u ON u.empleado_id = e.id
                    WHERE e.id = :id
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $data = $row ? [$row] : [];
            } else {
                $sql = "
                    SELECT e.id, e.nombre, e.apellido, e.departamento, e.tipo_contrato_id,
                           tc.name AS tipo_contrato_desc,
                           u.username, u.password, u.rol
                    FROM empleados e
                    LEFT JOIN tipos_contrato tc ON e.tipo_contrato_id = tc.id
                    LEFT JOIN usuarios u ON u.empleado_id = e.id
                ";
                $stmt = $pdo->query($sql);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(["meta" => $meta, "data" => $data]);
            break;

        case 'POST':
            if (empty($inputData)) {
                echo json_encode(["error" => "No data received for insert"]);
                return;
            }
            // Extract user fields so we don't try to put them into empleados
            $userFields = ['username','password','rol'];
            $username = $inputData['username'] ?? '';
            $password = $inputData['password'] ?? '';
            $rol      = $inputData['rol']      ?? '';
            // remove them from $inputData
            foreach ($userFields as $uf) {
                unset($inputData[$uf]);
            }

            // Insert into empleados
            $columns = array_keys($inputData);
            $placeholders = array_map(fn($c) => ':' . $c, $columns);
            $sql = "INSERT INTO empleados (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($inputData);
                $newId = $pdo->lastInsertId();

                // If username is not empty => create user row
                if (!empty($username)) {
                    // Just store password as-is; in real life, we'd hash it
                    $sqlU = "INSERT INTO usuarios (username, password, rol, empleado_id)
                             VALUES (:u, :p, :r, :eid)";
                    $stU = $pdo->prepare($sqlU);
                    $stU->execute([
                        ':u' => $username,
                        ':p' => $password,
                        ':r' => $rol ?: 'worker',
                        ':eid'=> $newId
                    ]);
                }

                echo json_encode(["success" => true, "id" => $newId]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        case 'PUT':
            if (!$id) {
                echo json_encode(["error" => "Missing ?id= for update"]);
                return;
            }
            if (empty($inputData)) {
                echo json_encode(["error" => "No data for update"]);
                return;
            }

            // Extract user fields
            $userFields = ['username','password','rol'];
            $username = $inputData['username'] ?? '';
            $password = $inputData['password'] ?? null; // might be empty string
            $rol      = $inputData['rol']      ?? '';
            foreach ($userFields as $uf) {
                unset($inputData[$uf]);
            }

            // Update empleados
            $sets = [];
            foreach ($inputData as $col => $val) {
                $sets[] = "$col = :$col";
            }
            $sql = "UPDATE empleados SET " . implode(",", $sets) . " WHERE id = :id";
            try {
                $stmt = $pdo->prepare($sql);
                $inputData['id'] = $id;
                $stmt->execute($inputData);

                // Now handle the user row
                //  - If username is empty => delete user row (if any)
                //  - Otherwise => create or update it

                // Find existing user row
                $stChk = $pdo->prepare("SELECT id,password FROM usuarios WHERE empleado_id = :eid LIMIT 1");
                $stChk->execute([':eid' => $id]);
                $userRow = $stChk->fetch(PDO::FETCH_ASSOC);

                if (empty($username)) {
                    // Remove user row if it exists
                    if ($userRow) {
                        $pdo->prepare("DELETE FROM usuarios WHERE id = :uid")->execute([':uid'=>$userRow['id']]);
                    }
                } else {
                    // We want a user row
                    if (!$userRow) {
                        // Insert new user
                        $sqlU = "INSERT INTO usuarios (username, password, rol, empleado_id)
                                 VALUES (:u, :p, :r, :eid)";
                        $stU = $pdo->prepare($sqlU);
                        $stU->execute([
                            ':u' => $username,
                            ':p' => $password ?? '',
                            ':r' => $rol ?: 'worker',
                            ':eid'=> $id
                        ]);
                    } else {
                        // Update existing
                        // If password is empty string => keep old password
                        $newPassword = $userRow['password'];
                        if ($password !== null && $password !== '') {
                            $newPassword = $password;
                        }
                        $sqlU = "UPDATE usuarios
                                 SET username=:u, password=:p, rol=:r
                                 WHERE id=:uid";
                        $stU = $pdo->prepare($sqlU);
                        $stU->execute([
                            ':u' => $username,
                            ':p' => $newPassword,
                            ':r' => $rol ?: 'worker',
                            ':uid'=> $userRow['id']
                        ]);
                    }
                }

                echo json_encode(["success" => true, "id" => $id]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        case 'DELETE':
            if (!$id) {
                echo json_encode(["error" => "Missing ?id= for delete"]);
                return;
            }
            try {
                // Also remove user row referencing this employee, if any
                $pdo->prepare("DELETE FROM usuarios WHERE empleado_id = :eid")->execute([':eid'=>$id]);

                // Then remove employee
                $stmt = $pdo->prepare("DELETE FROM empleados WHERE id = :id");
                $stmt->execute(['id' => $id]);
                echo json_encode(["success" => true, "id" => $id]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        default:
            echo json_encode(["error" => "Method not allowed: $method"]);
            break;
    }
}

/**
 * handleLogTime
 * 
 * Endpoint to be called by workers on index.php for clocking in/out with geolocation.
 */
function handleLogTime(PDO $pdo, $method, $inputData) {
    if ($method !== 'POST') {
        echo json_encode(["error" => "Method not allowed, use POST"]);
        return;
    }

    // Must be logged in with role=worker
    if (empty($_SESSION['user_id']) || ($_SESSION['user_rol'] ?? '') !== 'worker') {
        echo json_encode(["error" => "No active worker session"]);
        return;
    }

    $action = $inputData['action'] ?? null;
    $lat = $inputData['latitude'] ?? null;
    $lon = $inputData['longitude'] ?? null;

    if (!$action || !$lat || !$lon) {
        echo json_encode(["error" => "Missing action or lat/lon"]);
        return;
    }

    // We need empleado_id from session
    $empId = $_SESSION['empleado_id'] ?? null;
    if (!$empId) {
        echo json_encode(["error" => "No empleado_id linked to user"]);
        return;
    }

    // We'll store current date/time
    $fechaHoy = date('Y-m-d');
    $horaAhora = date('H:i');

    try {
        if ($action === 'entrance') {
            // Insert a new record in Asistencia
            $sql = "INSERT INTO Asistencia (
                        empleado_id, fecha, hora_entrada, lat_entrada, lon_entrada
                    ) VALUES (:emp, :fecha, :hentrada, :latE, :lonE)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':emp'     => $empId,
                ':fecha'   => $fechaHoy,
                ':hentrada'=> $horaAhora,
                ':latE'    => $lat,
                ':lonE'    => $lon
            ]);
            echo json_encode(["success" => true, "message" => "Entrada registrada"]);
        }
        elseif ($action === 'exit') {
            // Find the last "Asistencia" record for this employee that does not have a hora_salida yet
            $sqlFind = "
                SELECT id
                FROM Asistencia
                WHERE empleado_id = :emp
                  AND fecha = :fecha
                  AND (hora_salida IS NULL OR hora_salida = '')
                ORDER BY id DESC
                LIMIT 1
            ";
            $st = $pdo->prepare($sqlFind);
            $st->execute([
                ':emp'   => $empId,
                ':fecha' => $fechaHoy
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(["error" => "No hay registro de entrada para hoy"]);
                return;
            }

            $idAsistencia = $row['id'];
            $sqlUpdate = "
                UPDATE Asistencia
                SET hora_salida = :hsalida,
                    lat_salida = :latS,
                    lon_salida = :lonS
                WHERE id = :id
            ";
            $stmUp = $pdo->prepare($sqlUpdate);
            $stmUp->execute([
                ':hsalida' => $horaAhora,
                ':latS'    => $lat,
                ':lonS'    => $lon,
                ':id'      => $idAsistencia
            ]);
            echo json_encode(["success" => true, "message" => "Salida registrada"]);
        }
        else {
            echo json_encode(["error" => "Acción no reconocida"]);
        }
    } catch (PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
}

/** -----------------------------------------
 *  Generic CRUD for other endpoints
 * -----------------------------------------
 */
function crudGenerico(PDO $pdo, $tabla, $method, $id, $inputData) {
    $tablaReal = $tabla;
    switch ($method) {
        case 'GET':
            if ($id) {
                $sql = "SELECT * FROM $tablaReal WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $id]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $data = $pdo->query("SELECT * FROM $tablaReal")->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Retrieve table structure using PRAGMA table_info
            $stmt = $pdo->query("PRAGMA table_info($tablaReal)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fields = [];
            foreach ($columns as $col) {
                $fieldType = "text";
                if (stripos($col['type'], 'int') !== false) {
                    $fieldType = "number";
                }
                if ($tablaReal == 'Asistencia') {
                    if ($col['name'] == 'fecha') {
                        $fieldType = "date";
                    } elseif ($col['name'] == 'hora_entrada' || $col['name'] == 'hora_salida') {
                        $fieldType = "time";
                    }
                }
                if ($tablaReal == 'Vacaciones') {
                    if ($col['name'] == 'fecha_inicio' || $col['name'] == 'fecha_fin') {
                        $fieldType = "date";
                    }
                    if ($col['name'] == 'aprobado') {
                        $fieldType = "select";
                        $options = [
                            ["value" => 0, "label" => "No"],
                            ["value" => 1, "label" => "Sí"]
                        ];
                    }
                }
                if ($tablaReal == 'Contratos') {
                    if ($col['name'] == 'fecha_firma') {
                        $fieldType = "date";
                    }
                    if ($col['name'] == 'salario_anual') {
                        $fieldType = "number";
                    }
                }
                if ($tablaReal == 'Incidencias') {
                    if ($col['name'] == 'fecha') {
                        $fieldType = "date";
                    }
                }
                // For foreign key columns
                if (in_array($tablaReal, ['Asistencia','Vacaciones','Contratos','Incidencias']) && $col['name'] == 'empleado_id') {
                    $fieldType = "select";
                    $stmtEmp = $pdo->query("SELECT id, nombre || ' ' || apellido as display FROM empleados");
                    $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
                    $options = [];
                    foreach ($employees as $emp) {
                        $options[] = ["value" => $emp['id'], "label" => $emp['display']];
                    }
                }

                $field = [
                    "name" => $col['name'],
                    "label" => ucfirst($col['name']),
                    "type" => $fieldType,
                    "readonly" => ($col['name'] === "id")
                ];
                if (isset($options)) {
                    $field["options"] = $options;
                    unset($options);
                }
                $fields[] = $field;
            }
            $meta = ["fields" => $fields];
            echo json_encode(["meta" => $meta, "data" => $data]);
            break;

        case 'POST':
            if (empty($inputData)) {
                echo json_encode(["error" => "No data received for insert"]);
                return;
            }
            $cols = array_keys($inputData);
            $phs  = array_map(fn($c) => ":$c", $cols);
            $sql = "INSERT INTO $tablaReal (" . implode(",", $cols) . ") VALUES (" . implode(",", $phs) . ")";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($inputData);
                $newId = $pdo->lastInsertId();
                echo json_encode(["success" => true, "id" => $newId]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        case 'PUT':
            if (!$id) {
                echo json_encode(["error" => "Missing ?id= for update"]);
                return;
            }
            $sets = [];
            foreach ($inputData as $col => $val) {
                $sets[] = "$col = :$col";
            }
            $sql = "UPDATE $tablaReal SET " . implode(",", $sets) . " WHERE id = :id";
            try {
                $stmt = $pdo->prepare($sql);
                $inputData['id'] = $id;
                $stmt->execute($inputData);
                echo json_encode(["success" => true, "id" => $id]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        case 'DELETE':
            if (!$id) {
                echo json_encode(["error" => "Missing ?id= for delete"]);
                return;
            }
            $sql = "DELETE FROM $tablaReal WHERE id = :id";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $id]);
                echo json_encode(["success" => true, "id" => $id]);
            } catch (PDOException $e) {
                echo json_encode(["error" => $e->getMessage()]);
            }
            break;

        default:
            echo json_encode(["error" => "Method not allowed: $method"]);
            break;
    }
}

