{#
 #
 # Copyright (C) 2026 Pat Ryan
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright notice,
 #    this list of conditions and the following disclaimer in the documentation
 #    and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #
 #}

<script>
    $(document).ready(function () {
        mapDataToFormUI({'frm_GeneralSettings': '/api/mdnsbridge/settings/get'}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $('#{{ formGridIface["table_id"] }}').UIBootgrid({
            search: '/api/mdnsbridge/settings/search_iface',
            get: '/api/mdnsbridge/settings/get_iface/',
            set: '/api/mdnsbridge/settings/set_iface/',
            add: '/api/mdnsbridge/settings/add_iface/',
            del: '/api/mdnsbridge/settings/del_iface/',
            toggle: '/api/mdnsbridge/settings/toggle_iface/'
        });

        $('#{{ formGridPeer["table_id"] }}').UIBootgrid({
            search: '/api/mdnsbridge/settings/search_peer',
            get: '/api/mdnsbridge/settings/get_peer/',
            set: '/api/mdnsbridge/settings/set_peer/',
            add: '/api/mdnsbridge/settings/add_peer/',
            del: '/api/mdnsbridge/settings/del_peer/',
            toggle: '/api/mdnsbridge/settings/toggle_peer/'
        });

        /* Save the General tab, then let the button run service/reconfigure. */
        $('#reconfigureAct').SimpleActionButton({
            onPreAction: function () {
                const dfObj = $.Deferred();
                saveFormToEndpoint('/api/mdnsbridge/settings/set', 'frm_GeneralSettings', dfObj.resolve, true, dfObj.reject);
                return dfObj;
            },
            onAction: function () {
                loadGeneratedConfig();
            }
        });

        function loadGeneratedConfig() {
            ajaxGet('/api/mdnsbridge/service/showconfig', {}, function (data) {
                $('#generated_config').text(data && data.config ? data.config : '');
            });
        }

        $('a[href="#config"]').on('shown.bs.tab', loadGeneratedConfig);

        updateServiceControlUI('mdnsbridge');
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#interfaces">{{ lang._('Interfaces') }}</a></li>
    <li><a data-toggle="tab" href="#peers">{{ lang._('Peer rules') }}</a></li>
    <li><a data-toggle="tab" href="#config">{{ lang._('Generated configuration') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="general" class="tab-pane fade in active">
        {{ partial('layout_partials/base_form', ['fields': generalForm, 'id': 'frm_GeneralSettings']) }}
    </div>
    <div id="interfaces" class="tab-pane fade in">
        <div class="col-md-12 __mt __mb">
            {{ lang._('Each enabled entry is bridged. Inbound filters control what that network can advertise; outbound filters control what it can see. At least two entries are required.') }}
        </div>
        {{ partial('layout_partials/base_bootgrid_table', formGridIface) }}
    </div>
    <div id="peers" class="tab-pane fade in">
        <div class="col-md-12 __mt __mb">
            {{ lang._('Peer rules replace the outbound filter of the To interface for packets that come from one specific From interface.') }}
        </div>
        {{ partial('layout_partials/base_bootgrid_table', formGridPeer) }}
    </div>
    <div id="config" class="tab-pane fade in">
        <div class="col-md-12 __mt __mb">
            {{ lang._('The configuration file generated for mdns-bridge from the settings above, as last applied. Read-only.') }}
        </div>
        <pre id="generated_config" style="margin: 0 15px 15px 15px; white-space: pre-wrap;"></pre>
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/mdnsbridge/service/reconfigure', 'data_service_widget': 'mdnsbridge'}) }}
{{ partial('layout_partials/base_dialog', ['fields': formDialogIface, 'id': formGridIface['edit_dialog_id'], 'label': lang._('Edit bridge interface')]) }}
{{ partial('layout_partials/base_dialog', ['fields': formDialogPeer, 'id': formGridPeer['edit_dialog_id'], 'label': lang._('Edit peer rule')]) }}
