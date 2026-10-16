function Copy-SqlBackupIisPoolElement {
    param($Source,$Target,[switch]$PoolRoot)
    foreach ($attribute in $Source.Attributes) {
        if ($PoolRoot -and $attribute.Name -eq 'name') { continue }
        if ($Source.ElementTagName -eq 'processModel' -and $attribute.Name -in @('userName','password','identityType')) { continue }
        $Target.SetAttributeValue($attribute.Name,$attribute.Value)
    }
    foreach ($child in $Source.ChildElements) {
        Copy-SqlBackupIisPoolElement -Source $child -Target ($Target.GetChildElement($child.ElementTagName))
    }
    if ($Source.Schema.CollectionSchema) {
        $sourceItems=$Source.GetCollection()
        $targetItems=$Target.GetCollection()
        $targetItems.Clear()
        foreach ($item in $sourceItems) {
            $copy=$targetItems.CreateElement($item.ElementTagName)
            Copy-SqlBackupIisPoolElement -Source $item -Target $copy
            $targetItems.Add($copy)
        }
    }
}
