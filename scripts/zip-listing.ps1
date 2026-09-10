Add-Type -AssemblyName System.IO.Compression.FileSystem
$z = [System.IO.Compression.ZipFile]::OpenRead('dist\insightistic.4.4.2.zip')
$z.Entries | Sort-Object FullName | ForEach-Object { '{0,10}  {1}' -f $_.Length, $_.FullName }
$z.Dispose()
''
'Entries: ' + ($z.Entries.Count)
'SHA256:  ' + (Get-FileHash 'dist\insightistic.4.4.2.zip' -Algorithm SHA256).Hash
