<?php
// Muestra todos los errores para facilitar la depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cabeceras CORS básicas (permitir llamadas desde desarrollo local). Ajusta en producción.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight: devolver cabeceras y terminar
    http_response_code(204);
    exit;
}

// Carga la API Key de forma segura desde una variable de entorno
$apiKey = getenv('OPENAI_API_KEY'); 

if (!$apiKey) {
    echo json_encode(["error" => "OPENAI_API_KEY no está configurada"]);
    exit;
}


if (!$apiKey) {
    // Si no hay API key, no abortamos inmediatamente: permitimos modo mock para pruebas locales.
    // echo json_encode(["error" => "No se encontró la variable de entorno OPENAI_API_KEY"]);
    // exit;
    $apiKey = null;
}

// Recibe los datos enviados desde JavaScript
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if ($raw && $input === null) {
    echo json_encode(["error" => "JSON inválido en el cuerpo de la petición.", "raw" => $raw]);
    exit;
}

$baseId = $input["baseProductId"] ?? "";
$prefs = $input["preferences"] ?? [];

if (!$baseId) {
    echo json_encode(["error" => "No se recibió el ID del producto base (baseProductId)"]);
    exit;
}

// Carga el catálogo de productos de forma segura
$catalogPath = __DIR__ . "/products.json";
if (!file_exists($catalogPath)) {
    echo json_encode(["error" => "El archivo products.json no se encuentra."]);
    exit;
}
$catalogoRaw = file_get_contents($catalogPath);
$catalogo = json_decode($catalogoRaw, true);
if ($catalogo === null) {
    echo json_encode(["error" => "El archivo products.json contiene JSON inválido."]);
    exit;
}

// Busca el producto base en el catálogo
$baseProduct = null;
foreach ($catalogo as $p) {
    if ($p["id"] === $baseId) {
        $baseProduct = $p;
        break;
    }
}
if (!$baseProduct) {
    echo json_encode(["error" => "Producto base no encontrado en el catálogo."]);
    exit;
}

// Modo mock: si se envía cabecera X-MOCK: 1 o no hay API key devolvemos una respuesta simulada sin llamar a OpenAI
$mockHeader = $_SERVER['HTTP_X_MOCK'] ?? null;
if ($mockHeader === '1' || !$apiKey) {
    // Seleccionar algunas sugerencias simples (por ejemplo, todos menos el base)
    $mockSuggestions = array_filter($catalogo, function($p) use ($baseId) {
        return $p["id"] !== $baseId;
    });
    echo json_encode([
        "suggestions" => array_values($mockSuggestions),
        "raw" => ["mock" => true]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Construye el prompt para la IA
$system = "Eres un asesor de moda. Dado un producto base, sugiere combinaciones desde el catálogo.";
$user = "Producto base: " . $baseProduct["name"] . 
        " (color: " . ($baseProduct["colors"][0] ?? "N/A") . "). " .
        "Catálogo disponible: " . json_encode($catalogo, JSON_UNESCAPED_UNICODE) . ". " .
        "Ocasión: " . ($prefs["occasion"] ?? "cualquiera") . 
        ", Estilo: " . ($prefs["style"] ?? "cualquiera") . ". " .
        "Responde SOLO con un JSON válido con este formato: {\"suggestions\": [\"id_producto_1\",\"id_producto_2\"]}";

// Prepara y ejecuta la llamada a la API de OpenAI con cURL
$url = "https://api.openai.com/v1/chat/completions";
$data = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "system", "content" => $system],
        ["role" => "user", "content" => $user]
    ],
    "temperature" => 0.7
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(["error" => "Error de cURL: " . curl_error($ch)]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(["error" => "La API de OpenAI devolvió un error (HTTP $httpCode)", "raw_response" => json_decode($response)]);
    exit;
}

$resObj = json_decode($response, true);
$content = $resObj["choices"][0]["message"]["content"] ?? "";

$ids = [];
if ($content) {
    $parsed = json_decode($content, true);
    if (isset($parsed["suggestions"]) && is_array($parsed["suggestions"])) {
        $ids = $parsed["suggestions"];
    }
}

// Filtra el catálogo para devolver solo los productos sugeridos
$suggestions = [];
foreach ($catalogo as $p) {
    if (in_array($p["id"], $ids)) {
        $suggestions[] = $p;
    }
}

// Devuelve la respuesta final a JavaScript
// Modo mock: si se envía cabecera X-MOCK: 1 devolvemos una respuesta simulada sin llamar a OpenAI
$mockHeader = $_SERVER['HTTP_X_MOCK'] ?? null;
if ($mockHeader === '1' || !$apiKey) {
    // Seleccionar algunas sugerencias simples (por ejemplo, todos menos el base)
    $mockSuggestions = array_filter($catalogo, function($p) use ($baseId) {
        return $p["id"] !== $baseId;
    });
    echo json_encode([
        "suggestions" => array_values($mockSuggestions),
        "raw" => ["mock" => true]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    "suggestions" => $suggestions,
    "raw" => ["model_output" => $content]
], JSON_UNESCAPED_UNICODE);