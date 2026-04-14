param(
  [Parameter(Mandatory=$true)]
  [string]$BaseUrl,
  [string]$OutDir = ".\\webhook_check"
)

$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

function Save-Json($Path, $Data) {
  $json = $Data | ConvertTo-Json -Depth 10
  Set-Content -Path $Path -Value $json -Encoding UTF8
}

function Invoke-B24Post($Method, $Body, $OutFile) {
  $resp = Invoke-RestMethod -Method Post -Uri ($BaseUrl + $Method) -ContentType "application/json" -Body $Body -TimeoutSec 40
  Save-Json $OutFile $resp
}

try {
  $scope = Invoke-RestMethod -Method Get -Uri ($BaseUrl + "scope") -TimeoutSec 40
  Save-Json (Join-Path $OutDir "scope.json") $scope
} catch {
  Set-Content -Path (Join-Path $OutDir "scope.json") -Value $_.Exception.Message -Encoding UTF8
}

try {
  $user = Invoke-RestMethod -Method Get -Uri ($BaseUrl + "user.current") -TimeoutSec 40
  Save-Json (Join-Path $OutDir "user.current.json") $user
} catch {
  Set-Content -Path (Join-Path $OutDir "user.current.json") -Value $_.Exception.Message -Encoding UTF8
}

Invoke-B24Post "catalog.product.list" '{"select":["id","iblockId","name","active","code","previewText","detailText","previewPicture","detailPicture","iblockSectionId","property*"],"filter":{"iblockId":14},"order":{"id":"desc"}}' (Join-Path $OutDir "catalog.product.list.json")
Invoke-B24Post "catalog.product.get" '{"id":7896}' (Join-Path $OutDir "catalog.product.get_7896.json")
Invoke-B24Post "catalog.productProperty.list" '{"filter":{"iblockId":14},"select":["id","name","code","propertyType","userType","multiple"]}' (Join-Path $OutDir "catalog.productProperty.list.json")
Invoke-B24Post "catalog.section.list" '{"filter":{"iblockId":14},"select":["id","name","sort","sectionId","xmlId","code"]}' (Join-Path $OutDir "catalog.section.list.json")
Invoke-B24Post "catalog.price.list" '{"select":["id","productId","catalogGroupId","price","currency"],"filter":{"productId":7896}}' (Join-Path $OutDir "catalog.price.list_7896.json")

Write-Host "Done. Outputs in: $OutDir"
