<form data-rx-submit="refresh">
    <label><?=htmlspecialchars($labels['search'], ENT_QUOTES, "UTF-8"); ?>
        <input type="search" value="<?=htmlspecialchars($search, ENT_QUOTES, "UTF-8"); ?>" data-rx-bind="search" data-rx-reset="page:1" maxlength="100" aria-label="<?=htmlspecialchars($labels['search'], ENT_QUOTES, "UTF-8"); ?>">
    </label>
    <button type="submit"><?=htmlspecialchars($labels['search'], ENT_QUOTES, "UTF-8"); ?></button>
    <button type="button" data-rx-action="clear"><?=htmlspecialchars($labels['clear'], ENT_QUOTES, "UTF-8"); ?></button>
    <span data-rx-loading><?=htmlspecialchars($labels['loading'], ENT_QUOTES, "UTF-8"); ?></span>
</form>
<?php if(isset($errors['search'])): ?>
<p role="alert"><?=htmlspecialchars($errors['search'], ENT_QUOTES, "UTF-8"); ?></p>
<?php endif; ?>
<p data-total><?=htmlspecialchars($total, ENT_QUOTES, "UTF-8"); ?> <?=htmlspecialchars($labels['products'], ENT_QUOTES, "UTF-8"); ?></p>
<?php if(count($products) > 0): ?>
<table>
    <thead><tr><th scope="col"><?=htmlspecialchars($labels['name'], ENT_QUOTES, "UTF-8"); ?></th><th scope="col"><?=htmlspecialchars($labels['price'], ENT_QUOTES, "UTF-8"); ?></th></tr></thead>
    <tbody>
    <?php foreach($products as $product): ?>
    <tr><td><?=htmlspecialchars($product->name, ENT_QUOTES, "UTF-8"); ?></td><td><?=htmlspecialchars(number_format((float) $product->price, 2), ENT_QUOTES, "UTF-8"); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p data-empty><?=htmlspecialchars($labels['empty'], ENT_QUOTES, "UTF-8"); ?></p>
<?php endif; ?>
<nav aria-label="<?=htmlspecialchars($labels['pagination'], ENT_QUOTES, "UTF-8"); ?>">
    <button type="button" data-rx-action="goToPage" data-rx-set="page" data-rx-value="<?=htmlspecialchars(max(1, $page - 1), ENT_QUOTES, "UTF-8"); ?>" <?php if($page <= 1): ?> disabled <?php endif; ?>><?=htmlspecialchars($labels['previous'], ENT_QUOTES, "UTF-8"); ?></button>
    <span data-page><?=htmlspecialchars($page, ENT_QUOTES, "UTF-8"); ?> / <?=htmlspecialchars($last, ENT_QUOTES, "UTF-8"); ?></span>
    <button type="button" data-rx-action="goToPage" data-rx-set="page" data-rx-value="<?=htmlspecialchars($page + 1, ENT_QUOTES, "UTF-8"); ?>" <?php if($page >= $last): ?> disabled <?php endif; ?>><?=htmlspecialchars($labels['next'], ENT_QUOTES, "UTF-8"); ?></button>
</nav>
