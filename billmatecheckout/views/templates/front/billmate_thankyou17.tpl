{extends file=$layout}

{block name='content'}
<div class="container">


    {if isset($HOOK_ORDER_CONFIRMATION)}
    <div id="order-conf">
            {$HOOK_ORDER_CONFIRMATION}

    </div>
    {/if}
</div>
{/block}