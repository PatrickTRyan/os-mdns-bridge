{#
 # Copyright (C) 2026 Patrick Ryan
 # All rights reserved.
 #}

<script>
$(document).ready(function () {

    mapDataToFormUI({
        'frm_GeneralSettings': "/api/mdnsbridge/settings/get"
    }).done(function () {

        formatTokenizersUI();
        $('.selectpicker').selectpicker('refresh');

        updateServiceControlUI('mdnsbridge');
    });

    $("#reconfigureAct").SimpleActionButton({

        onPreAction: function () {

            const dfObj = $.Deferred();

            saveFormToEndpoint(
                "/api/mdnsbridge/settings/set",
                'frm_GeneralSettings',
                dfObj.resolve,
                true,
                dfObj.reject
            );

            return dfObj;
        },

        onAction: function (data, status) {

            if (status === "success" && data.status === 'ok') {

                ajaxCall(
                    "/api/mdnsbridge/service/reconfigure",
                    {},
                    function (reconfigData, reconfigStatus) {

                        if (
                            reconfigStatus === "success" &&
                            reconfigData.status === 'ok'
                        ) {
                            updateServiceControlUI('mdnsbridge');
                        }
                    }
                );
            }
        }
    });

    updateServiceControlUI('mdnsbridge');
});
</script>

<section class="page-content-main">

    <div class="content-box">
        {{
            partial(
                "layout_partials/base_form",
                {
                    'fields': general,
                    'id': 'frm_GeneralSettings'
                }
            )
        }}
    </div>

    <br/>

    <div class="content-box">

        <div class="col-md-12">

            <br/>

            <button
                class="btn btn-primary"
                id="reconfigureAct"
                data-endpoint="/api/mdnsbridge/service/reconfigure"
                data-label="{{ lang._('Apply') }}"
                type="button">

                {{ lang._('Apply') }}

            </button>

            <br/><br/>

        </div>

    </div>

</section>
