Messager = {

    flash: function(message) {
        $('#message').text(message);
        $('#message-wrapper').show();
    },

    hideFlash: function() {
        $('#message-wrapper').hide();
        $('#message').text('');
    },

    alert: function (status, message) {
        message = message || '';

        if (status == 1) {
            message = '<span class="message message-success">' + message + '</span><br />';
        } else {
            message = '<span class="message message-alert">' + message + '</span><br />';
        }
        $("#messages-panel-anchor").before(message);
        $('#messages-panel').scrollTop($('#messages-panel')[0].scrollHeight);
    }

}

Git = {

    database_name: '',
    schemas_states: {},
    schemas_objects_states: {},
    last_hash: '',
    diff_template: '',
    commits_template: '',

    request: function(method, url, data, success) {
        Messager.flash('Pending...');
        $.ajax({
            url: url,
            dataType: "json",
            type: method,
            data: data
        }).done(function(data) {
            Messager.hideFlash();
            success(data);
        }).fail(function() {
            Messager.hideFlash();
            Messager.alert(0, 'Unable to make request');
        });
    },

    onApplyCheck: function(e) {
        // when apply is checked/is not checked also check/uncheck forward (if exists)
        var forward_id = $(e).attr('id').replace(/^apply/, 'forward');
        var forward = $("#" + forward_id);
        if ( ! forward.length) {
            return;
        }
        forward.prop('checked', $(e).prop('checked'));
    },

    onForwardCheck: function(e) {
        // when forward is checked also check apply
        var apply_id = $(e).attr('id').replace(/^forward/, 'apply');
        var apply = $("#" + apply_id);
        if ($(e).prop('checked')) {
            apply.prop('checked', true);
        }
    },

    checkout: function (hash, show_alert, f) {
        f = f || function() {};
        Git.request(
            'GET',
            '/' + Git.database_name + '/' + hash + '/checkout/',
            { },
            function (data) {
                if (data.status == 1) {
                    $(".commit").removeClass("commit-active");
                    Git.clearDiff();
                    data.commit_hash = hash;
                    //
                    $("#diff").html(Mustache.render(Git.diff_template, data));
                    // listen to apply checkboxes
                    $(".apply.apply-main").change(function() {
                        Git.onApplyCheck(this);
                    });
                    // listen to forward checkboxes
                    $(".apply.forward").change(function() {
                        Git.onForwardCheck(this);
                    });
                    //
                    Git.last_hash = hash;
                    //
                    Git.getCommits(function() {
                        if (show_alert) {
                            // show current hash or branch
                            Messager.alert(1, 'Checked out to ' + hash);

                            var forwardable_types = ['queries_before', 'sequences', 'tables', 'queries_after'];

                            for (var forwardable_type_key in forwardable_types) {
                                var forwardable_type = forwardable_types[forwardable_type_key];

                                //
                                var cbf = data['stat']['can_be_forwarded'][forwardable_type];
                                if (cbf.length) {
                                    Messager.alert(1, 'Can be forwarded ' + forwardable_type + ': ' + cbf.join(' -> '));
                                }
                                //
                                var cnbf = data['stat']['cannot_be_forwarded'][forwardable_type];
                                if (cnbf.length) {
                                    Messager.alert(1, 'Cannot be forwarded ' + forwardable_type + ': ' + cnbf.join(', '));
                                }
                            }
                        }
                        f();
                    });
                } else {
                    Messager.alert(0, data.message);
                }
            }
        );
    },

    clearDiff: function() {
        $("#diff").children().remove();
    },

    clearCommits: function() {
        $("#commits").children().remove();
    },

    toggleSchema: function(schema) {
        if (Git.schemas_states[schema] == undefined) {
            Git.schemas_states[schema] = false;
        }
        Git.schemas_states[schema] = ! Git.schemas_states[schema];
        $(".s-" + schema).prop('checked', Git.schemas_states[schema]);
    },

    toggleSchemaObject: function(schema, object_index) {
        if (Git.schemas_objects_states[schema] == undefined) {
            Git.schemas_objects_states[schema] = {};
        }
        if (Git.schemas_objects_states[schema][object_index] == undefined) {
            Git.schemas_objects_states[schema][object_index] = false;
        }
        Git.schemas_objects_states[schema][object_index] = ! Git.schemas_objects_states[schema][object_index];
        $(".s-" + schema).filter(".o-" + object_index).prop('checked', Git.schemas_objects_states[schema][object_index]);
    },

    toggleSchemaObjectTable: function(schema, object_index) {
        $("#row_" + schema + "_" + object_index).slideToggle();
    },

    apply: function(imitate) {
        imitate = imitate || false;

        var objects = [];

        // foreach chosen object
        $(".apply-main").filter(":checked").each(function(n, e) {
            var forward_order = 0;
            // do we have to use forward? let's see at special checkbox:
            var ff = $("input[name='" + e.name + "/forward_order']");
            var forwarded = false;
            if (ff.length) {
                // checkbox exists (for tables only)
                forwarded = $(ff.get(0)).prop('checked');
                // order is in value
                forward_order = $(ff.get(0)).attr('value');
            }
            // gather information
            objects.push({
                object_name: e.name,
                forwarded: forwarded ? 1 : 0,
                forward_order: forward_order
            });
        });

        //
        if (objects.length == 0) {
            Messager.alert(1, 'Nothing to ' + (! imitate ? 'apply' : 'imitate'));
            return;
        }

        Git.request(
            'POST',
            '/' + Git.database_name + '/apply/',
            {
                objects: objects,
                imitate: imitate ? 1 : 0
            },
            function (data) {
                Messager.alert(data.status, data.status == 1 ? (! imitate ? 'Applied' : 'Imitated') : data.message);
                if (data.status == 1) {
                    Git.checkout(Git.last_hash, false);
                }
            }
        );
    },

    drop: function(schema_name, object_index, object_name) {
        if (! confirm('Are you sure to drop ' + schema_name + '.' + object_name + '?')) {
            return;
        }

        Git.request(
            'POST',
            '/' + Git.database_name + '/' + schema_name + '/' + object_index + '/' + object_name + '/drop/' ,
            {

            },
            function (data) {
                Messager.alert(data.status, data.status == 1 ? 'Dropped' : data.message);
                if (data.status == 1) {
                    Git.checkout(Git.last_hash, false);
                }
            }
        );
    },

    reloadAndApply: function() {
        Git.checkout(Git.last_hash, false, function() {
            // check all checkboxes (they are not checked by default)
            $(".apply").prop('checked', true);
            //
            Git.apply();
        })
    },

    switchToBranch: function (branch) {
        Git.checkout(branch, true);
    },

    imitate: function() {
        if (confirm('It will fill postgresql_deployer.migrations table without actual deploying objects. Are you sure?')) {
            Git.apply(true);
        }
    },

    getCommits: function(f) {
        f = f || function() {};
        Git.request(
            'GET',
            '/' + Git.database_name + '/get_commits/',
            { },
            function (data) {
                if (data.status == 1) {
                    Git.clearCommits();
                    $("#commits").html(Mustache.render(Git.commits_template, data));
                    f();
                } else {
                    Messager.alert(0, data['message']);
                }
            }
        );
    },

    changeDatabase: function() {
        Git.last_hash = '';
        Git.schemas_states = [];
        Git.schemas_objects_states = [];
    }

  }

