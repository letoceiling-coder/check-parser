# Setup Yandex Vision API

$yandexPassportOauthToken = "y0__xDwrvltGMHdEyCQjLulFjCYqviFCGG9k7UtEeeRka30BZfv2kmemOCf"
$Body = @{ yandexPassportOauthToken = "$yandexPassportOauthToken" } | ConvertTo-Json -Compress

Write-Host "Exchanging OAuth token for IAM token..." -ForegroundColor Yellow
$iamResponse = Invoke-RestMethod -Method 'POST' -Uri 'https://iam.api.cloud.yandex.net/iam/v1/tokens' -Body $Body -ContentType 'Application/json'
$IAM_TOKEN = $iamResponse.iamToken

Write-Host "IAM token received: $($IAM_TOKEN.Substring(0, 20))..." -ForegroundColor Green

Write-Host "`nGetting clouds list..." -ForegroundColor Yellow
$cloudsResponse = Invoke-RestMethod -Method 'GET' -Uri 'https://resource-manager.api.cloud.yandex.net/resource-manager/v1/clouds' -Headers @{ "Authorization" = "Bearer $IAM_TOKEN" }

Write-Host "Found clouds: $($cloudsResponse.clouds.Count)" -ForegroundColor Green
foreach ($cloud in $cloudsResponse.clouds) {
    Write-Host "  - $($cloud.name) (ID: $($cloud.id))" -ForegroundColor Cyan
}

if ($cloudsResponse.clouds.Count -gt 0) {
    $cloudId = $cloudsResponse.clouds[0].id
    Write-Host "`nGetting folders for cloud: $cloudId..." -ForegroundColor Yellow
    $foldersResponse = Invoke-RestMethod -Method 'GET' -Uri "https://resource-manager.api.cloud.yandex.net/resource-manager/v1/folders?cloudId=$cloudId" -Headers @{ "Authorization" = "Bearer $IAM_TOKEN" }
} else {
    $foldersResponse = @{ folders = @() }
}

Write-Host "Found folders: $($foldersResponse.folders.Count)" -ForegroundColor Green
foreach ($folder in $foldersResponse.folders) {
    Write-Host "  - $($folder.name) (ID: $($folder.id))" -ForegroundColor Cyan
}

Write-Host "`n=== Add to .env file ===" -ForegroundColor Magenta
Write-Host "YANDEX_VISION_API_KEY=$IAM_TOKEN" -ForegroundColor White
if ($foldersResponse.folders.Count -gt 0) {
    Write-Host "YANDEX_FOLDER_ID=$($foldersResponse.folders[0].id)" -ForegroundColor White
} else {
    Write-Host "YANDEX_FOLDER_ID=your_folder_id" -ForegroundColor White
}

Write-Host "`nNOTE: IAM token is valid for 12 hours only!" -ForegroundColor Red
