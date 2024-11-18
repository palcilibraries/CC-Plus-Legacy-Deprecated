<template>
  <div class="d-flex mt-2">
    <form>
      <v-row class="d-flex ma-0" no-gutters>
        <v-col v-for="item in config_settings" class="d-flex pa-2" cols="4" :key="item.id">
          <v-text-field v-model="item.value" :label="item.name" outlined dense
                        :disabled="item.name=='root_url' || item.name=='reports_path'"
          ></v-text-field>
        </v-col>
      </v-row>
      <v-row class="d-flex ma-0" no-gutters>
        <h3>Mail Settings</h3>
      </v-row>
      <v-row class="d-flex ma-0" no-gutters>
        <v-col v-for="item in mail_settings"  class="d-flex px-2" cols="4" :key="item.id">
          <v-text-field v-if="item.name=='mail_password'" v-model="item.value" :label="item.name" outlined dense
                        :type="pw_show ? 'text' : 'password'" @click:append="pw_show = !pw_show"
                        :append-icon="pw_show ? 'mdi-eye-off' : 'mdi-eye'" autocomplete="new-mail-password">
          </v-text-field>
          <v-text-field v-else-if="item.name=='mail_username'" v-model="item.value" :label="item.name" outlined dense
                        autocomplete="new-mail-username"
          ></v-text-field>
          <v-text-field v-else v-model="item.value" :label="item.name" outlined dense></v-text-field>
        </v-col>
      </v-row>
      <v-row v-if="success!=''" class="status-message">
        <span class="good" role="alert" v-text="success"></span>
      </v-row>
      <v-row v-if="failure!=''" class="status-message">
        <span class="fail" role="alert" v-text="failure"></span>
      </v-row>
      <v-row class="d-flex ma-0" no-gutters>
        <v-col class="d-flex pa-0 justify-center">
          <v-btn small color="primary" type="button" @click="formSubmit()">Update All Settings</v-btn>
        </v-col>
      </v-row>
    </form>
  </div>
</template>
<script>
  import Form from '@/js/plugins/Form';
  window.Form = Form;
  export default {
    props: {
      settings: { type:Array, default: () => [] },
    },
    data () {
      return {
        success: '',
        failure: '',
        config_settings: [],
        mail_settings: [],
        pw_show: false,
      }
    },
    methods: {
        formSubmit (event) {
            this.success = '';
            this.failure = '';
            var combined_settings = this.config_settings.concat(this.mail_settings);
            axios.post('/server/config', {
                all_globals: combined_settings
            })
            .then( (response) => {
                if (response.data.result) {
                    this.success = response.data.msg;
                } else {
                    this.failure = response.data.msg;
                }
            })
            .catch(error => {});
        },
    },
    mounted() {
      this.config_settings = this.settings.filter( s=> s.type=='config' );
      this.mail_settings = this.settings.filter( s=> s.type=='mail' );
      console.log('Server Settings component mounted.');
    }
  }
</script>
<style>
</style>
