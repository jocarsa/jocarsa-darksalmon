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
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $json = file_get_contents("php://input");
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
    }
}

// Helper: Haversine formula to calculate distance (in km)
function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Earth radius in km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// 2. Route by endpoint
switch ($endpoint) {
    case 'menu':
        echo json_encode([
            ["etiqueta" => "Empleados",      "endpoint" => "Empleados"],
            ["etiqueta" => "Asistencia",      "endpoint" => "Asistencia"],
            ["etiqueta" => "Vacaciones",      "endpoint" => "Vacaciones"],
            ["etiqueta" => "Contratos",       "endpoint" => "Contratos"],
            ["etiqueta" => "Incidencias",     "endpoint" => "Incidencias"],
            ["etiqueta" => "Centros de Trabajo", "endpoint" => "CentrosTrabajo"]
        ]);
        break;

    case 'CentrosTrabajo': // New endpoint for centros de trabajo
        crudGenerico($pdo, 'centros_trabajo', $method, $id, $inputData);
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
 *  - Enhanced to also store/edit user info and include centro de trabajo and radio de acción
 * -----------------------------------------
 */
function handleEmpleados(PDO $pdo, $method, $id, $inputData) {
    switch ($method) {
        case 'GET':
            // Prepare options for centro de trabajo (for select)
            $stmtCentro = $pdo->query("SELECT id, name FROM centros_trabajo");
            $optionsCentros = [];
            while ($ct = $stmtCentro->fetch(PDO::FETCH_ASSOC)) {
                $optionsCentros[] = [ "value" => $ct['id'], "label" => $ct['name'] ];
            }

            $stmt = $pdo->query("SELECT id, name FROM tipos_contrato");
            $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $optionsTipo = [];
            foreach ($tipos as $t) {
                $optionsTipo[] = [ "value" => $t['id'], "label" => $t['name'] ];
            }

            $optionsRol = [
                ["value" => "admin",  "label" => "Admin"],
                ["value" => "worker", "label" => "Trabajador"]
            ];

            $meta = [
                "fields" => [
                    [ "name" => "id",               "label" => "ID",            "type" => "number", "readonly" => true ],
                    [ "name" => "nombre",           "label" => "Nombre",        "type" => "text" ],
                    [ "name" => "apellido",         "label" => "Apellido",      "type" => "text" ],
                    [ "name" => "departamento",     "label" => "Departamento",  "type" => "text" ],
                    [
                        "name" => "tipo_contrato_id",
                        "label" => "Tipo Contrato",
                        "type" => "select",
                        "options" => $optionsTipo
                    ],
                    // New fields for centro de trabajo and radio de acción:
                    [
                        "name" => "centro_trabajo_id",
                        "label" => "Centro de Trabajo",
                        "type" => "select",
                        "options" => $optionsCentros
                    ],
                    [ "name" => "radio_accion",     "label" => "Radio de acción (km)", "type" => "number" ],
                    // User account fields:
                    [ "name" => "username",         "label" => "Usuario",       "type" => "text" ],
                    [ "name" => "password",         "label" => "Contraseña",    "type" => "text" ],
                    [
                        "name" => "rol",
                        "label" => "Rol",
                        "type" => "select",
                        "options" => $optionsRol
                    ],
                ]
            ];

            if ($id) {
                $sql = "
                    SELECT e.id, e.nombre, e.apellido, e.departamento, e.tipo_contrato_id,
                           tc.name AS tipo_contrato_desc,
                           e.centro_trabajo_id,
                           ct.name AS centro_trabajo_desc,
                           e.radio_accion,
                           u.username, u.password, u.rol
                    FROM empleados e
                    LEFT JOIN tipos_contrato tc ON e.tipo_contrato_id = tc.id
                    LEFT JOIN centros_trabajo ct ON e.centro_trabajo_id = ct.id
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
                           e.centro_trabajo_id,
                           ct.name AS centro_trabajo_desc,
                           e.radio_accion,
                           u.username, u.password, u.rol
                    FROM empleados e
                    LEFT JOIN tipos_contrato tc ON e.tipo_contrato_id = tc.id
                    LEFT JOIN centros_trabajo ct ON e.centro_trabajo_id = ct.id
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
            $userFields = ['username', 'password', 'rol'];
            $username = $inputData['username'] ?? '';
            $password = $inputData['password'] ?? '';
            $rol      = $inputData['rol']      ?? '';
            foreach ($userFields as $uf) {
                unset($inputData[$uf]);
            }
            $columns = array_keys($inputData);
            $placeholders = array_map(fn($c) => ':' . $c, $columns);
            $sql = "INSERT INTO empleados (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")";
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($inputData);
                $newId = $pdo->lastInsertId();
                if (!empty($username)) {
                    $sqlU = "INSERT INTO usuarios (username, password, rol, empleado_id)
                             VALUES (:u, :p, :r, :eid)";
                    $stU = $pdo->prepare($sqlU);
                    $stU->execute([
                        ':u' => $username,
                        ':p' => $password,
                        ':r' => $rol ?: 'worker',
                        ':eid' => $newId
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
            $userFields = ['username', 'password', 'rol'];
            $username = $inputData['username'] ?? '';
            $password = $inputData['password'] ?? null;
            $rol      = $inputData['rol']      ?? '';
            foreach ($userFields as $uf) {
                unset($inputData[$uf]);
            }
            $sets = [];
            foreach ($inputData as $col => $val) {
                $sets[] = "$col = :$col";
            }
            $sql = "UPDATE empleados SET " . implode(",", $sets) . " WHERE id = :id";
            try {
                $stmt = $pdo->prepare($sql);
                $inputData['id'] = $id;
                $stmt->execute($inputData);
                $stChk = $pdo->prepare("SELECT id,password FROM usuarios WHERE empleado_id = :eid LIMIT 1");
                $stChk->execute([':eid' => $id]);
                $userRow = $stChk->fetch(PDO::FETCH_ASSOC);
                if (empty($username)) {
                    if ($userRow) {
                        $pdo->prepare("DELETE FROM usuarios WHERE id = :uid")->execute([':uid' => $userRow['id']]);
                    }
                } else {
                    if (!$userRow) {
                        $sqlU = "INSERT INTO usuarios (username, password, rol, empleado_id)
                                 VALUES (:u, :p, :r, :eid)";
                        $stU = $pdo->prepare($sqlU);
                        $stU->execute([
                            ':u' => $username,
                            ':p' => $password ?? '',
                            ':r' => $rol ?: 'worker',
                            ':eid' => $id
                        ]);
                    } else {
                        $newPassword = $userRow['password'];
                        if ($password !== null && $password !== '') {
                            $newPassword = $password;
                        }
                        $sqlU = "UPDATE usuarios
                                 SET username = :u, password = :p, rol = :r
                                 WHERE id = :uid";
                        $stU = $pdo->prepare($sqlU);
                        $stU->execute([
                            ':u' => $username,
                            ':p' => $newPassword,
                            ':r' => $rol ?: 'worker',
                            ':uid' => $userRow['id']
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
                $pdo->prepare("DELETE FROM usuarios WHERE empleado_id = :eid")->execute([':eid' => $id]);
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

/** -----------------------------------------
 *  handleLogTime
 *  - Endpoint for clocking in/out with geolocation and calculating distance to assigned centro de trabajo
 * -----------------------------------------
 */
function handleLogTime(PDO $pdo, $method, $inputData) {
    if ($method !== 'POST') {
        echo json_encode(["error" => "Method not allowed, use POST"]);
        return;
    }
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
    $empId = $_SESSION['empleado_id'] ?? null;
    if (!$empId) {
        echo json_encode(["error" => "No empleado_id linked to user"]);
        return;
    }
    
    // Retrieve the centro de trabajo assigned to this employee, including its coordinates and the employee's radio_accion
    $sqlCentro = "
        SELECT ct.latitude, ct.longitude, e.radio_accion 
        FROM empleados e 
        LEFT JOIN centros_trabajo ct ON e.centro_trabajo_id = ct.id 
        WHERE e.id = :empId
    ";
    $stCentro = $pdo->prepare($sqlCentro);
    $stCentro->execute([':empId' => $empId]);
    $centro = $stCentro->fetch(PDO::FETCH_ASSOC);
    if (!$centro || empty($centro['latitude']) || empty($centro['longitude'])) {
        echo json_encode(["error" => "No centro de trabajo assigned or incomplete data"]);
        return;
    }
    
    // Calculate the distance (in km) using the Haversine formula
    $distance = haversine_distance($lat, $lon, $centro['latitude'], $centro['longitude']);

    $fechaHoy = date('Y-m-d');
    $horaAhora = date('H:i');
    try {
        if ($action === 'entrance') {
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
            echo json_encode([
                "success" => true,
                "message" => "Entrada registrada. Distancia al centro: " . round($distance, 2) . " km"
            ]);
        } elseif ($action === 'exit') {
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
            echo json_encode([
                "success" => true,
                "message" => "Salida registrada. Distancia al centro: " . round($distance, 2) . " km"
            ]);
        } else {
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
            
            // For Asistencia endpoint, generate Google Maps links on the backend
            if ($tablaReal == 'Asistencia') {
                foreach ($data as &$row) {
                    $row['mapa_entrada'] = (!empty($row['lat_entrada']) && !empty($row['lon_entrada']))
                        ? "<a href='https://www.google.com/maps/search/?api=1&query=" . $row['lat_entrada'] . "," . $row['lon_entrada']."' target='_blank'>Mapa</a>"
                        : "";
                    $row['mapa_salida'] = (!empty($row['lat_salida']) && !empty($row['lon_salida']))
                        ? "<a href='https://www.google.com/maps/search/?api=1&query=" . $row['lat_salida'] . "," . $row['lon_salida']."' target='_blank'>Mapa</a>"
                        : "";
                }
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
                if (in_array($tablaReal, ['Asistencia', 'Vacaciones', 'Contratos', 'Incidencias']) && $col['name'] == 'empleado_id') {
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
?>

