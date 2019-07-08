<template>
  <b-container>
    <div v-if="loading">
      <spinner size="medium" message="Loading editor..." v-if="loading"></spinner>
    </div>
    <div v-else>
      <p>
        Export as <a :href="settings.SERVICE_URL+'/export/lineup/'+lineup.id+'/?fileformat=json'">JSON</a> or
        <a :href="settings.SERVICE_URL+'/export/lineup/'+lineup.id + '/?fileformat=yaml'">YAML</a>.
      </p>

      <b-card :title="lineupCardHeadline" v-if="loading === false" class="mb-4">
        {{lineup.notes}}<br>
        Headends: {{lineup.headends_string}}<br>
        <a href="#" @click.prevent="editLineup" v-if="isAdmin">Edit</a>
      </b-card>

      <b-alert variant="danger" :show="!loading && error !== ''">{{error}}</b-alert>

      <div v-for="cluTier in channelsByTiers" :key="cluTier.id" class="clu-tier-container mt-4">
        <clu-tier
          :sourceChannels="channelsList"
          :tierChannels="cluTier.channels"
          :tierName="cluTier.name"
          :tierId="cluTier.id"
          :usedChannels="usedChannels"
          :sortOrder="cluTier.sort_order"
          :tiersNum="lineup.tiers.length"
          v-on:prompt="channelAddPrompt"
          v-on:removechannel="channelRemove"
          v-on:removetier="tierRemove"
          v-on:movetierdown="tierMoveDown"
          v-on:movetierup="tierMoveUp" />
      </div>

      <div class="d-flex pt-4" v-if="isAdmin">
        <div class="pb-4">
          <b-form inline @submit.prevent="addTier">
            <b-form-group id="tierSelectGroup" description="">
              <b-form-select v-model="selectedToAddTier" :options="tiersOptionsList">
                <template slot="first">
                  <option :value="null" disabled>-- Please select a tier --</option>
                </template>
              </b-form-select>
              <b-button type="submit" variant="primary">Add tier</b-button>
            </b-form-group>
          </b-form>
        </div>
      </div>

      <b-modal ref="channelNumberEditModal" title="Set channel number" hide-footer no-fade>
        <div>
          <b-form @submit.prevent="setChannelNumberAndAddItToTier" class="breakeable">
              <b-form-group id="setChannelNumberGroup" :description="channelNumberDescription">
                <b-form-input v-model="modalChannelNumber" maxlength="12" placeholder="Enter channel number" required :state="formState"></b-form-input>
                <b-form-invalid-feedback>{{formInvalidFeedback.channel_num[0]}}</b-form-invalid-feedback>
              </b-form-group>
              <b-form-group class="text-center">
                <b-button type="submit" variant="primary" :disabled="modalSetChannelNumButtonDisabled">Set</b-button>
              </b-form-group>
          </b-form>
        </div>
      </b-modal>

      <b-modal ref="lineupEditModal" title="Edit lineup description" hide-footer no-fade>
        <b-container>
          <component
            :is="view.component"
            :id="view.data.id"
            :name="view.data.name"
            :effectiveDate="view.data.effectivedate"
            :notes="view.data.notes"
            :selectedHeadends="view.data.headends_list"
            v-on:update = "afterEditLineup" />
        </b-container>
      </b-modal>
    </div>
  </b-container>
</template>

<script>
  import * as settings from '../settings'
  import CluTier from './parts/CluTier'
  import LineupForm from './forms/LineupForm'
  import axios from 'axios'
  import _ from 'lodash'

  export default {
    components: {CluTier, LineupForm},
    name: 'CLUEditor',
    props: {
      clu: {
        type: String,
        default: '0'
      }
    },
    created: function () {
      this.loading = true
      this.fetchData(this.clu)
    },
    data: function () {
      return {
        loading: true,
        error: '',
        selectedToAddTier: null,
        lineup: {},
        tiersList: [],
        sourceChannels: [],
        modalSetChannelNumButtonDisabled: false,
        modalChannelData: {id: null, name: ''},
        modalChannelNumber: '',
        modalTierId: null,
        formState: null,
        formInvalidFeedback: {
          channel_num: []
        },
        settings: settings
      }
    },
    computed: {
      lineupCardHeadline: function () {
        return this.lineup === undefined ? '' : 'Lineup ' + this.lineup.id + ': ' + this.lineup.name
      },
      tiersOptionsList: function () {
        return _.map(this.tiersList, elem => { return {text: elem.name, value: elem.id} })
      },
      channelsList: function () {
        return _.toArray(this.sourceChannels)
      },
      channelNumberDescription: function () {
        return 'Please set channel number for ' + this.modalChannelData.name
      },
      channelsByTiers: function () {
        return _.orderBy(_.toArray(this.lineup.tiers), 'sort_order')
      },
      view: function () {
        return {
          component: 'lineup-form',
          data: this.lineup
        }
      },
      usedChannels: function () {
        if (this.lineup.tiers.length === 0) {
          return []
        }

        let usedChannels = []
        _.forEach(this.lineup.tiers, value => {
          _.reduce(value.channels, (result, redValue, key) => {
            (result || (result = [])).push(redValue.id)
            return result
          }, usedChannels)
        })

        return usedChannels
      },
      usedNumbers: function () {
        if (this.channelsByTiers.length === 0) {
          return []
        }

        let usedNumbers = []
        _.forEach(this.lineup.tiers, value => {
          _.reduce(value.channels, (result, redValue, key) => {
            (result || (result = [])).push(redValue.num)
            return result
          }, usedNumbers)
        })

        return usedNumbers
      },
      isAdmin: function () {
        return this.$store.getters.isAdmin
      }
    },
    methods: {
      addTier: function () {
        let tierId = this.selectedToAddTier

        let tierIdx = this.tiersList.findIndex(value => value.id === tierId)
        if (tierIdx === -1) {
          return
        }

        let tierIdxInChannels = this.lineup.tiers.findIndex(value => value.id === tierId)
        if (tierIdxInChannels > -1) {
          alert('This tier is already in lineup')
          return
        }

        this.lineup.tiers.push({
          id: tierId,
          name: this.tiersList[tierIdx].name,
          channels: [],
          sort_order: this.lineup.tiers.length + 1
        })

        this.selectedToAddTier = null
      },

      channelNumberIsInParticularTier: function (tierId, channelNum) {
        let tierChannels = _.filter(this.channelsByTiers, {id: tierId})
        if (tierChannels.length > 0) {
          let channelsWithSameNumber = _.filter(tierChannels[0].channels, {num: channelNum})
          return channelsWithSameNumber.length > 0
        }
        return false
      },

      fetchData: function (id) {
        axios.get(settings.API_ENDPOINT + '/lineups/' + id)
          .then(response => {
            this.lineup = response.data.data
            // this.tiersChannels = response.data.channelsByTiers
            this.loading = false
          })
          .catch(() => {
            this.loading = false
          })
        axios.get(settings.API_ENDPOINT + '/channels/')
          .then(response => {
            this.sourceChannels = response.data.data
            this.loading = false
          })
          .catch(() => {
            this.loading = false
          })
        axios.get(settings.API_ENDPOINT + '/tiers/')
          .then(response => {
            this.tiersList = response.data.data
            this.loading = false
          })
          .catch(() => {
            this.loading = false
          })
      },

      setChannelNumberAndAddItToTier: function () {
        this.formState = null

        if (this.channelNumberIsInParticularTier(this.modalTierId, this.modalChannelNumber.trim())) {
          this.formState = false
          this.formInvalidFeedback = {channel_num: ['There is a channel with the same number in this tier']}
          return
        }

        let indexOfUsedChannel = this.usedNumbers.indexOf(this.modalChannelNumber)
        if (indexOfUsedChannel !== -1) {
          if (!confirm('This channel number has already been taken. Are you sure you want to use it again?')) {
            return
          }
        }

        this.modalSetChannelNumButtonDisabled = true
        axios.post(settings.API_ENDPOINT + '/lineups/' + this.lineup.id + '/content', {
          lineup_id: this.lineup.id,
          tier_id: this.modalTierId,
          channel_id: this.modalChannelData.id,
          channel_num: this.modalChannelNumber
        })
          .then(response => {
            let targetTier = this.channelsByTiers.findIndex(value => value.id === this.modalTierId)
            this.channelsByTiers[targetTier].channels.push({
              id: response.data.data.id,
              channel_id: this.modalChannelData.id,
              name: this.modalChannelData.name,
              num: this.modalChannelNumber
            })

            this.modalSetChannelNumButtonDisabled = false
            this.$refs.channelNumberEditModal.hide()

            this.$toasted.show(this.modalChannelData.name + ' has been added to tier ' + this.channelsByTiers[targetTier].name, {
              theme: 'outline',
              duration: 5000
            })

            this.modalChannelData = {id: null, name: ''}
            this.modalChannelNumber = ''
            this.modalTierId = null
          })
          .catch(error => {
            this.modalSetChannelNumButtonDisabled = false
            if (error.response.status !== undefined && error.response.status === 422) {
              this.formState = false
              this.formInvalidFeedback = error.response.data.errors
            }
            if (error.response.data.error !== undefined) {
              this.showError(error.response.data.error.message)
            }
          })
      },

      channelAddPrompt: function (promptData) {
        this.formState = null
        this.modalChannelNumber = ''
        this.modalChannelData = {id: promptData.channelId, name: promptData.channelName}
        this.modalTierId = promptData.tierId
        this.$refs.channelNumberEditModal.show()
      },

      channelRemove: function (removeData) {
        if (confirm('Do you really want to delete this channel from the tier?')) {
          axios.delete(settings.API_ENDPOINT + '/lineups/' + this.lineup.id + '/content/' + removeData.contentId)
            .then(response => {
              let targetTier = this.channelsByTiers.findIndex(value => value.id === removeData.tierId)
              let channelIdx = this.channelsByTiers[targetTier].channels.findIndex(value => value.id === removeData.contentId)
              if (channelIdx > -1) {
                this.$toasted.show(this.channelsByTiers[targetTier].channels[channelIdx].name +
                  ' has been removed from tier ' + this.channelsByTiers[targetTier].name, {
                    theme: 'outline',
                    duration: 5000
                  })

                this.channelsByTiers[targetTier].channels.splice(channelIdx, 1)
              }
            })
            .catch((error) => {
              if (error.response.data.error !== undefined) {
                this.showError(error.response.data.error.message)
              }
            })
        }
      },

      tierRemove: function (id) {
        axios.delete(settings.API_ENDPOINT + '/lineups/' + this.lineup.id + '/tier/' + id)
          .then(response => {
            let tierIdx = this.lineup.tiers.findIndex(value => value.id === id)
            if (tierIdx > -1) {
              this.$toasted.show('Tier ' + this.lineup.tiers[tierIdx].name + ' has been removed from lineup', {
                theme: 'outline',
                duration: 5000
              })

              this.lineup.tiers.splice(tierIdx, 1)
            }
          })
          .catch((error) => {
            if (error.response.data.error !== undefined) {
              this.showError(error.response.data.error.message)
            }
          })
      },

      tierMoveUp: function (id) {
        this.tierMove(id, 'up')
      },

      tierMoveDown: function (id) {
        this.tierMove(id, 'down')
      },

      tierMove: function (id, direction) {
        axios.patch(settings.API_ENDPOINT + '/lineups/' + this.lineup.id + '/tier/' + id + '/move' + direction + '/')
          .then(response => {
            this.loading = true
            this.fetchData(this.clu)
          })
          .catch((error) => {
            if (error.response.data.error !== undefined) {
              this.showError(error.response.data.error.message)
            }
          })
      },

      editLineup: function () {
        this.$refs.lineupEditModal.show()
      },

      afterEditLineup: function (lineupData) {
        if (lineupData.error !== undefined) {
          this.showError(lineupData.error)
          return
        }
        this.$refs.lineupEditModal.hide()
        this.$set(this, 'lineup', lineupData)
      },

      showError: function (message) {
        this.$refs.lineupEditModal.hide()
        this.$refs.channelNumberEditModal.hide()
        this.error = message
      }
    }
  }
</script>

<style scoped>
  .clu-tier-container{ border-bottom: 1px solid #343a40 }
</style>
