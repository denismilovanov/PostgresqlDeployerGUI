Messager = {

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
        $.ajax({
            url: url,
            dataType: "json",
            type: method,
            data: data
        }).done(function(data) {
            success(data);
        }).fail(function() {
            Messager.alert(0, 'Unable to make request');
        });
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
                    $("#commit-" + hash).addClass("commit-active");
                    Git.clearDiff();
                    data.commit_hash = hash;
                    $("#diff").html(Mustache.render(Git.diff_template, data));
                    Git.last_hash = hash;
                    Git.getCommits(function() {
                        if (show_alert) {
                            Messager.alert(1, 'Checked out to ' + hash);
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

        $(".apply").filter(":checked").each(function(n, e) {
            objects.push(e.name);
        });

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

