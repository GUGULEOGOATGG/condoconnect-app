<?php if ($user['tipo_usuario']==='admin'): ?>
<form method="get" class="panel-card community-selector">
    <label for="community-building">Condomínio</label>
    <select id="community-building" name="condominio_id" required>
        <?php foreach(dbRows('SELECT id,nome FROM condominios ORDER BY nome,id') as $option): ?>
        <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id']===$cond?'selected':'' ?>><?= sanitize($option['nome']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="button button-secondary" type="submit">Selecionar condomínio</button>
</form>
<?php endif; ?>
